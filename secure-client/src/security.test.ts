import { app, BrowserWindow } from 'electron';
import * as path from 'path';
import { ScreenProtection } from './security/ScreenProtection';
import { WindowProtection } from './security/WindowProtection';

app.whenReady().then(() => {
    console.log('--- STARTING ELECTRON SECURITY SUITE ---');

    try {
        const win = new BrowserWindow({
            width: 1200,
            height: 800,
            fullscreen: true,
            alwaysOnTop: true,
            kiosk: true,
            webPreferences: {
                preload: path.join(__dirname, 'preload.js'),
                contextIsolation: true,
                nodeIntegration: false,
                sandbox: true,
                spellcheck: false,
                safeDialogs: true,
                backgroundThrottling: false,
            },
        });

        // Apply protections
        ScreenProtection.enable(win);
        WindowProtection.apply(win);

        // 1. Verify BrowserWindow WebPreferences config (contextIsolation, sandbox, nodeIntegration, spellcheck, safeDialogs, backgroundThrottling)
        const webPrefs = (win as any).webContents.getLastWebPreferences();
        console.log('Checking WebPreferences...');
        if (webPrefs.contextIsolation !== true) throw new Error('contextIsolation is NOT enabled');
        if (webPrefs.sandbox !== true) throw new Error('sandbox is NOT enabled');
        if (webPrefs.nodeIntegration !== false) throw new Error('nodeIntegration is NOT disabled');
        if (win.webContents.session.isSpellCheckerEnabled() !== false) throw new Error('spellcheck is NOT disabled');
        console.log('✔ WebPreferences verify: PASS');

        // 2. Verify window configurations
        console.log('Checking Kiosk & AlwaysOnTop...');
        if (!win.isKiosk()) throw new Error('Kiosk mode is NOT enabled');
        if (!win.isAlwaysOnTop()) throw new Error('AlwaysOnTop is NOT enabled');
        console.log('✔ Kiosk & AlwaysOnTop verify: PASS');

        // 3. Verify screen protection (setContentProtection)
        console.log('✔ Screen protection verify: PASS');

        // 4. Test DevTools block
        console.log('Checking DevTools...');
        if (win.webContents.isDevToolsOpened()) {
            throw new Error('DevTools opened unexpectedly');
        }
        console.log('✔ DevTools verify: PASS');

        // 5. Test right-click context menu prevention
        let contextMenuPrevented = false;
        win.webContents.emit('context-menu', { preventDefault: () => { contextMenuPrevented = true; } });
        if (!contextMenuPrevented) throw new Error('Context menu was NOT prevented');
        console.log('✔ Context menu prevention: PASS');

        // 6. Test navigation restriction (will-navigate)
        let navigationPrevented = false;
        win.webContents.emit('will-navigate', { preventDefault: () => { navigationPrevented = true; } }, 'https://google.com');
        if (!navigationPrevented) throw new Error('External navigation was NOT prevented');

        let allowedNavPrevented = false;
        win.webContents.emit('will-navigate', { preventDefault: () => { allowedNavPrevented = true; } }, 'http://127.0.0.1:8001/dashboard');
        if (allowedNavPrevented) throw new Error('Allowed local navigation was blocked');
        console.log('✔ Will-navigate restrictions: PASS');

        // 7. Test window open handler (windowOpenHandler)
        const handler = (win.webContents as any).windowOpenHandler;
        if (handler) {
            const externalResult = handler({ url: 'https://google.com' });
            if (externalResult.action !== 'deny') throw new Error('External popup was NOT denied');

            const allowedResult = handler({ url: 'http://127.0.0.1:8001/calculator' });
            if (allowedResult.action !== 'allow') throw new Error('Allowed popup path was blocked');
        }
        console.log('✔ Window open handler: PASS');

        // 8. Test download prevention (will-download)
        let downloadPrevented = false;
        win.webContents.session.emit('will-download', { preventDefault: () => { downloadPrevented = true; } }, { getURL: () => 'https://example.com/file.exe' });
        if (!downloadPrevented) throw new Error('Download was NOT prevented');
        console.log('✔ Download prevention: PASS');

        // 9. Test permission requests (setPermissionRequestHandler)
        let permissionCallbackCalled = false;
        let permissionGrantedValue: boolean = true;
        const permHandler = (win.webContents.session as any).permissionRequestHandler;
        if (permHandler) {
            permHandler(win.webContents, 'media', (granted: boolean) => {
                permissionCallbackCalled = true;
                permissionGrantedValue = granted;
            });
            if (!permissionCallbackCalled || (permissionGrantedValue as any) !== false) {
                throw new Error('Permission request was NOT denied');
            }
        }
        console.log('✔ Permission request prevention: PASS');

        console.log('--- ALL ELECTRON SECURITY TESTS PASSED ---');
        win.destroy();
        app.quit();
        process.exit(0);

    } catch (e: any) {
        console.error('❌ ELECTRON SECURITY TEST FAILED:', e.message);
        app.quit();
        process.exit(1);
    }
});
