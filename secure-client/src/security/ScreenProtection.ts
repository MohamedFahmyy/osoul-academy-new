import { BrowserWindow } from 'electron';

export class ScreenProtection {
    /**
     * Prevent screen recording and screenshots on Windows and macOS.
     */
    public static enable(window: BrowserWindow): void {
        window.setContentProtection(true);
        console.log('Content protection (anti-screenshot/recording) enabled.');
    }
}
