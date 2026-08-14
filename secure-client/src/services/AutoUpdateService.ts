import { autoUpdater } from 'electron-updater';
import { LoggingService } from './LoggingService';

export class AutoUpdateService {
    private static instance: AutoUpdateService;

    private constructor() {
        // Configure logging for autoUpdater
        autoUpdater.logger = console;

        // Listen for events
        autoUpdater.on('checking-for-update', () => {
            LoggingService.log('autoupdate.checking', 'info', 'N/A');
        });

        autoUpdater.on('update-available', (info) => {
            LoggingService.log('autoupdate.available', 'info', 'N/A', {
                version: info.version
            });
        });

        autoUpdater.on('update-not-available', () => {
            LoggingService.log('autoupdate.not_available', 'info', 'N/A');
        });

        autoUpdater.on('error', (err) => {
            LoggingService.log('autoupdate.error', 'error', 'N/A', {
                message: err.message
            });
            console.error('AutoUpdate encountered an error. Initiating graceful rollback fallback:', err.message);
            // Rollback: Keep current runtime operational and do not force restart
        });

        autoUpdater.on('download-progress', (progressObj) => {
            console.log(`Update download progress: ${progressObj.percent}%`);
        });

        autoUpdater.on('update-downloaded', (info) => {
            LoggingService.log('autoupdate.downloaded', 'info', 'N/A', {
                version: info.version
            });
            // Prompt user or automatically install on next clean launch
        });
    }

    public static getInstance(): AutoUpdateService {
        if (!AutoUpdateService.instance) {
            AutoUpdateService.instance = new AutoUpdateService();
        }
        return AutoUpdateService.instance;
    }

    /**
     * Check for updates on the configured channel.
     */
    public checkForUpdates(channel: 'dev' | 'staging' | 'production' = 'production') {
        try {
            autoUpdater.channel = channel;
            autoUpdater.checkForUpdatesAndNotify();
        } catch (e: any) {
            LoggingService.log('autoupdate.failed_to_check', 'error', 'N/A', {
                error: e.message
            });
        }
    }
}
