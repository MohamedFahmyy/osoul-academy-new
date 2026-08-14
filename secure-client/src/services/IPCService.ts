import { ipcMain } from 'electron';
import { CHANNELS } from '../ipc/channels';
import { SessionService } from './SessionService';
import { HeartbeatService } from './HeartbeatService';
import * as crypto from 'crypto';

export class IPCService {
    /**
     * Register main process IPC handlers to process bootstraps and security events.
     */
    public static register(onAction: (action: string, reason: string) => void) {
        // 1. Session bootstrap handler (passive check, never exposes key to renderer)
        ipcMain.handle(CHANNELS.BOOTSTRAP, async (event, token: string, sessionId: string, backendUrl?: string) => {
            const session = SessionService.getInstance();
            const isVerified = session.isSessionVerified(token, sessionId);
            if (isVerified) {
                HeartbeatService.getInstance().setOnAction(onAction);
                HeartbeatService.getInstance().start();
            }
            return isVerified;
        });

        // 2. Incident event reporting handler
        ipcMain.handle(CHANNELS.EVENT, async (event, eventCode: string, severity: string, category: string, payload: any) => {
            const session = SessionService.getInstance();
            const sessionId = session.getSessionId();
            const keyId = session.getSessionKeyId();
            const rawKey = session.getSessionKey();
            const token = session.getToken();

            if (!sessionId || !keyId || !rawKey || !token) {
                return false;
            }

            const eventUuid = crypto.randomUUID();
            const clientSequence = 1;
            const occurredAt = new Date().toISOString();

            const signaturePayload = {
                session_id: sessionId,
                session_key_id: keyId,
                event_uuid: eventUuid,
                event_code: eventCode,
                payload: payload,
                severity: severity,
                source: 'client',
                category: category,
                client_sequence: clientSequence,
                occurred_at: occurredAt
            };

            const normalize = (data: any): any => {
                if (typeof data !== 'object' || data === null) {
                    return data;
                }
                if (Array.isArray(data)) {
                    return data.map(normalize);
                }
                const sortedKeys = Object.keys(data).sort();
                const result: any = {};
                for (const key of sortedKeys) {
                    result[key] = normalize(data[key]);
                }
                return result;
            };

            const sorted = normalize(signaturePayload);
            const signature = crypto.createHmac('sha256', rawKey).update(JSON.stringify(sorted)).digest('hex');

            try {
                const response = await fetch(`${session.getBackendUrl()}/api/v1/asap/event`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`,
                        'Accept': 'application/json',
                        'X-Correlation-ID': crypto.randomUUID(),
                    },
                    body: JSON.stringify({
                        ...signaturePayload,
                        signature: signature,
                    }),
                });

                return response.ok;
            } catch (error) {
                console.error('Error reporting security event via IPC:', error);
                return false;
            }
        });
    }
}
