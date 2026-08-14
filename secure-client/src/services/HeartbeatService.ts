import { app } from 'electron';
import { SessionService } from './SessionService';
import { LoggingService } from './LoggingService';
import * as crypto from 'crypto';
import * as path from 'path';
import * as fs from 'fs';

export class HeartbeatService {
    private static instance: HeartbeatService;
    private timer: NodeJS.Timeout | null = null;
    private isPausedState: boolean = false;
    private attempt: number = 0;
    private onActionCallback: ((action: string, reason: string) => void) | null = null;

    private constructor() {}

    public static getInstance(): HeartbeatService {
        if (!HeartbeatService.instance) {
            HeartbeatService.instance = new HeartbeatService();
        }
        return HeartbeatService.instance;
    }

    public setOnAction(callback: (action: string, reason: string) => void) {
        this.onActionCallback = callback;
    }

    /**
     * Start the heartbeat loop (adjusted based on pause state).
     */
    public start() {
        this.stop();
        const interval = this.isPausedState ? 15000 : 5000;
        this.timer = setInterval(() => this.tick(), interval);
        console.log(`Heartbeat loop started. Interval: ${interval}ms`);
    }

    /**
     * Stop the heartbeat loop.
     */
    public stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    /**
     * Transition the heartbeat service into a paused state.
     */
    public setPaused(paused: boolean) {
        this.isPausedState = paused;
        this.start(); // Restart loop with the new interval
    }

    public isPaused(): boolean {
        return this.isPausedState;
    }

    /**
     * Main tick function executing telemetry collection, signature, and retry.
     */
    private async tick() {
        const session = SessionService.getInstance();
        const sessionId = session.getSessionId();
        const keyId = session.getSessionKeyId();
        const rawKey = session.getSessionKey();
        const token = session.getToken();

        if (!sessionId || !keyId || !rawKey || !token) {
            return;
        }

        const correlationId = crypto.randomUUID();

        // 1. Check if there are queued offline telemetries
        const queue = this.loadQueue();
        if (queue.length > 0) {
            console.log(`Attempting to upload ${queue.length} queued telemetries...`);
            const success = await this.uploadBatch(queue, correlationId);
            if (success) {
                this.clearQueue();
                this.attempt = 0;
                LoggingService.log('network.restored', 'info', correlationId, {
                    message: 'Reconnected and flushed offline telemetry queue.'
                });
            } else {
                this.handleNetworkFailure(correlationId);
                return;
            }
        }

        // 2. Prepare current heartbeat telemetry payload
        const payload = {
            is_focused: !this.isPausedState,
            is_fullscreen: true,
            status: this.isPausedState ? 'paused' : 'running',
            occurred_at: new Date().toISOString()
        };

        const signaturePayload = {
            session_id: sessionId,
            session_key_id: keyId,
            payload: payload,
        };

        const sorted = this.normalize(signaturePayload);
        const signature = crypto.createHmac('sha256', rawKey).update(JSON.stringify(sorted)).digest('hex');

        try {
            const response = await fetch(`${session.getBackendUrl()}/api/v1/asap/telemetry/heartbeat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json',
                    'X-Correlation-ID': correlationId,
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    session_key_id: keyId,
                    signature: signature,
                    payload: payload,
                }),
            });

            if (!response.ok) {
                const text = await response.text().catch(() => '');
                throw new Error(`Server returned status code: ${response.status}. Response: ${text}`);
            }

            // Successfully sent
            this.attempt = 0;
            const data = (await response.json()) as any;

            if (data.status === 'success' && data.session) {
                const sessionStatus = data.session.status;
                if (sessionStatus === 'warning' && this.onActionCallback) {
                    LoggingService.log('policy.warn', 'warning', correlationId);
                    this.onActionCallback('warn', 'Session risk warning issued.');
                } else if (sessionStatus === 'paused' && this.onActionCallback) {
                    LoggingService.log('policy.pause', 'warning', correlationId);
                    this.onActionCallback('pause', 'Session paused by administrator.');
                } else if (sessionStatus === 'terminated' && this.onActionCallback) {
                    LoggingService.log('policy.terminate', 'critical', correlationId);
                    this.onActionCallback('terminate', 'Session terminated by security policy.');
                }
            }
        } catch (error: any) {
            console.error('Telemetry tick failed:', error);
            LoggingService.log('telemetry.failed', 'error', correlationId, {
                error: error.message || String(error),
                stack: error.stack
            });
            // Queue current heartbeat locally
            this.queueTelemetry(payload);
            this.handleNetworkFailure(correlationId);
        }
    }

    /**
     * Implement Exponential Backoff with Full Jitter.
     */
    private handleNetworkFailure(correlationId: string) {
        this.attempt++;
        LoggingService.log('network.disconnected', 'warning', correlationId, {
            attempt: this.attempt
        });

        const cap = 30000;
        const base = 1000;
        const temp = Math.min(cap, base * Math.pow(2, this.attempt));
        const sleepJitter = Math.random() * temp;

        console.warn(`Network unavailable. Retrying in ${Math.round(sleepJitter)}ms (attempt: ${this.attempt}).`);

        this.stop();
        this.timer = setTimeout(() => {
            this.start();
        }, sleepJitter);
    }

    /**
     * Send all accumulated offline payloads to the server in a single batch.
     */
    private async uploadBatch(queue: any[], correlationId: string): Promise<boolean> {
        const session = SessionService.getInstance();
        const token = session.getToken();
        const sessionId = session.getSessionId();
        const keyId = session.getSessionKeyId();
        const rawKey = session.getSessionKey();

        if (!token || !sessionId || !keyId || !rawKey) return false;

        const eventUuid = crypto.randomUUID();
        const clientSequence = 9999;
        const occurredAt = new Date().toISOString();
        const payload = { batched_ticks: queue };

        const signaturePayload = {
            session_id: sessionId,
            session_key_id: keyId,
            event_uuid: eventUuid,
            event_code: 'OFFLINE_TELEMETRY_FLUSH',
            payload: payload,
            severity: 'info',
            source: 'client',
            category: 'network',
            client_sequence: clientSequence,
            occurred_at: occurredAt
        };

        const sorted = this.normalize(signaturePayload);
        const signature = crypto.createHmac('sha256', rawKey).update(JSON.stringify(sorted)).digest('hex');

        try {
            const response = await fetch(`${session.getBackendUrl()}/api/v1/asap/event`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json',
                    'X-Correlation-ID': correlationId,
                },
                body: JSON.stringify({
                    ...signaturePayload,
                    signature: signature,
                })
            });

            if (!response.ok) {
                const text = await response.text().catch(() => '');
                LoggingService.log('upload_batch.failed', 'error', correlationId, {
                    status: response.status,
                    body: text
                });
                return false;
            }
            return true;
        } catch (e: any) {
            LoggingService.log('upload_batch.error', 'error', correlationId, {
                error: e.message || String(e)
            });
            return false;
        }
    }

    /**
     * Queue telemetry payload locally in an encrypted file.
     */
    private queueTelemetry(payload: any) {
        const queue = this.loadQueue();
        queue.push(payload);
        this.saveQueue(queue);
    }

    private saveQueue(queue: any[]) {
        const session = SessionService.getInstance();
        const sessionId = session.getSessionId();
        if (!sessionId) return;

        try {
            const filePath = path.join(app.getPath('userData'), 'telemetry-queue.dat');
            const serialized = JSON.stringify(queue);
            
            const key = crypto.createHash('sha256').update(sessionId).digest();
            const iv = crypto.randomBytes(16);
            const cipher = crypto.createCipheriv('aes-256-cbc', key, iv);
            
            let encrypted = cipher.update(serialized, 'utf8', 'hex');
            encrypted += cipher.final('hex');

            fs.writeFileSync(filePath, JSON.stringify({ iv: iv.toString('hex'), data: encrypted }), 'utf8');
        } catch (e) {
            console.error('Failed to save persistent queue:', e);
        }
    }

    private loadQueue(): any[] {
        const session = SessionService.getInstance();
        const sessionId = session.getSessionId();
        if (!sessionId) return [];

        const filePath = path.join(app.getPath('userData'), 'telemetry-queue.dat');
        if (!fs.existsSync(filePath)) {
            return [];
        }

        try {
            const payload = JSON.parse(fs.readFileSync(filePath, 'utf8'));
            const key = crypto.createHash('sha256').update(sessionId).digest();
            const iv = Buffer.from(payload.iv, 'hex');
            const decipher = crypto.createDecipheriv('aes-256-cbc', key, iv);
            
            let decrypted = decipher.update(payload.data, 'hex', 'utf8');
            decrypted += decipher.final('utf8');
            
            return JSON.parse(decrypted);
        } catch (e) {
            return [];
        }
    }

    private clearQueue() {
        const filePath = path.join(app.getPath('userData'), 'telemetry-queue.dat');
        if (fs.existsSync(filePath)) {
            fs.unlinkSync(filePath);
        }
    }

    private normalize(data: any): any {
        if (typeof data !== 'object' || data === null) {
            return data;
        }
        if (Array.isArray(data)) {
            return data.map(item => this.normalize(item));
        }
        const sortedKeys = Object.keys(data).sort();
        const result: any = {};
        for (const key of sortedKeys) {
            result[key] = this.normalize(data[key]);
        }
        return result;
    }
}
