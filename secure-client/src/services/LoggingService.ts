import { app } from 'electron';
import * as path from 'path';
import * as fs from 'fs';

export class LoggingService {
    private static logFilePath: string;

    private static init() {
        if (!this.logFilePath) {
            const userDataPath = app.getPath('userData');
            const logDir = path.join(userDataPath, 'logs');
            if (!fs.existsSync(logDir)) {
                fs.mkdirSync(logDir, { recursive: true });
            }
            this.logFilePath = path.join(logDir, 'asap-client.log');
        }
    }

    /**
     * Write structured JSON log to local client log file.
     */
    public static log(event: string, severity: 'info' | 'warning' | 'error' | 'critical', correlationId: string, details: any = {}): void {
        this.init();

        const logEntry = {
            timestamp: new Date().toISOString(),
            correlation_id: correlationId,
            event: event,
            severity: severity,
            details: details,
        };

        const logLine = JSON.stringify(logEntry) + '\n';
        fs.appendFileSync(this.logFilePath, logLine, 'utf8');
        console.log(`[ASAP LOG] [${severity.toUpperCase()}] ${event} (Corr-ID: ${correlationId})`);
    }
}
