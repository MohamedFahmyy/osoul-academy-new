import { LoggingService } from './LoggingService';

export class SentryService {
    /**
     * Capture and report an error to Sentry, filtering out sensitive authentication parameters.
     */
    public static captureException(error: Error, context: any = {}): void {
        const cleanedContext = { ...context };
        const sensitiveKeys = [
            'sessionkey', 
            'rawkey', 
            'token', 
            'session_key', 
            'authorization', 
            'key', 
            'signature',
            'session_key_encrypted'
        ];

        // Clean PII and tokens from context parameters
        for (const key of Object.keys(cleanedContext)) {
            if (sensitiveKeys.includes(key.toLowerCase())) {
                cleanedContext[key] = '[FILTERED_SENSITIVE_DATA]';
            }
        }

        const report = {
            timestamp: new Date().toISOString(),
            error: {
                message: error.message,
                stack: error.stack,
            },
            context: cleanedContext,
        };

        LoggingService.log('sentry.report_cleansed', 'error', 'N/A', {
            message: error.message
        });

        console.log('[Sentry Scaffold] Cleansed payload recorded:', JSON.stringify(report));

        // Production integration:
        // fetch(SENTRY_DSN_ENDPOINT, { method: 'POST', body: JSON.stringify(report) });
    }
}
