import { BrowserWindow, globalShortcut } from 'electron';
import { HeartbeatService } from '../services/HeartbeatService';

export class WindowProtection {
    /**
     * Disable developer shortcut hotkeys, context menus, and restrict navigations.
     */
    public static apply(window: BrowserWindow): void {
        // Disable DevTools shortcuts (F12, Ctrl+Shift+I) and block keyboard when paused
        window.webContents.on('before-input-event', (event, input) => {
            if (HeartbeatService.getInstance().isPaused()) {
                event.preventDefault();
                return;
            }

            if (input.key === 'F12' || 
                (input.control && input.shift && input.key.toLowerCase() === 'i') ||
                (input.meta && input.alt && input.key.toLowerCase() === 'i')) {
                event.preventDefault();
            }
        });

        // Disable right click context menus
        window.webContents.on('context-menu', (e) => {
            e.preventDefault();
        });

        // Restrict navigating away from allowed hostnames
        window.webContents.on('will-navigate', (event, url) => {
            const allowedHosts = ['127.0.0.1', 'localhost', 'mentor-lms-learning-management-system.test'];
            try {
                const parsedUrl = new URL(url);
                if (!allowedHosts.includes(parsedUrl.hostname)) {
                    event.preventDefault();
                    console.warn(`Blocked navigation attempt to: ${url}`);
                }
            } catch (e) {
                event.preventDefault();
            }
        });

        // Restrict popups to approved allowed paths
        window.webContents.setWindowOpenHandler(({ url }) => {
            try {
                const parsedUrl = new URL(url);
                const allowedHosts = ['127.0.0.1', 'localhost', 'mentor-lms-learning-management-system.test'];
                if (!allowedHosts.includes(parsedUrl.hostname)) {
                    console.warn(`Blocked external popup to: ${url}`);
                    return { action: 'deny' };
                }

                const allowedPaths = ['/calculator', '/pdf-viewer', '/dashboard', '/courses', '/exams'];
                const isAllowedPath = allowedPaths.some(path => parsedUrl.pathname.startsWith(path));

                if (isAllowedPath) {
                    return { action: 'allow' };
                }
            } catch (e) {
                // fallthrough to deny
            }

            console.warn(`Blocked popup attempt to unapproved route: ${url}`);
            return { action: 'deny' };
        });

        // Prevent downloads
        window.webContents.session.on('will-download', (event, item) => {
            event.preventDefault();
            console.warn(`Blocked download attempt for: ${item.getURL()}`);
        });

        // Block permission requests (camera, microphone, clipboard, etc.)
        window.webContents.session.setPermissionRequestHandler((webContents, permission, callback) => {
            console.warn(`Blocked permission request for: ${permission}`);
            callback(false);
        });

        // Disable spellcheck programmatically
        window.webContents.session.setSpellCheckerEnabled(false);
    }

    /**
     * Register global OS key blockers.
     */
    public static registerGlobalShortcuts(): void {
        globalShortcut.register('CommandOrControl+Escape', () => {
            console.log('Blocked OS system shortcut combination.');
        });
    }

    /**
     * Release all registered shortcut hooks.
     */
    public static unregisterGlobalShortcuts(): void {
        globalShortcut.unregisterAll();
    }
}
