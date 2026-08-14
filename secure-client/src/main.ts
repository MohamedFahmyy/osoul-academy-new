import { app, BrowserWindow } from 'electron';
import * as path from 'path';
import * as fs from 'fs';
import { ScreenProtection } from './security/ScreenProtection';
import { WindowProtection } from './security/WindowProtection';
import { IPCService } from './services/IPCService';
import { HeartbeatService } from './services/HeartbeatService';
import { SessionService } from './services/SessionService';
import { LoggingService } from './services/LoggingService';

let mainWindow: BrowserWindow | null = null;

function writeDeepLinkProof(url: string) {
    try {
        const testingDir = 'c:/laragon/www/mentor-lms-learning-management-system/storage/testing';
        if (!fs.existsSync(testingDir)) {
            fs.mkdirSync(testingDir, { recursive: true });
        }
        fs.writeFileSync(path.join(testingDir, 'asap_received_url.txt'), url, 'utf8');
        console.log('Successfully wrote deep link proof file.');
    } catch (err) {
        console.error('Failed to write deep link proof file:', err);
    }
}


/**
 * Parse and validate deep link URLs against an allowlist.
 */
interface LaunchContext {
    bootstrapToken: string;
    attempt: string;
    signature: string;
}

function parseLaunchParams(urlStr: string): LaunchContext | null {
    try {
        console.log(`Parsing deep link launch params: ${urlStr}`);
        const parsedUrl = new URL(urlStr);
        const bootstrapToken = parsedUrl.searchParams.get('bootstrapToken');
        const attempt = parsedUrl.searchParams.get('attempt');
        const signature = parsedUrl.searchParams.get('signature');

        if (bootstrapToken && attempt && signature) {
            return { bootstrapToken, attempt, signature };
        }
    } catch (e) {
        console.error('Failed to parse launch parameters:', e);
    }
    return null;
}

function validateTargetUrl(urlStr: string): boolean {
    try {
        const url = new URL(urlStr);

        // 1. Validate scheme
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            console.error(`Invalid scheme rejected: ${url.protocol}`);
            return false;
        }

        // 2. Validate host (from env or config)
        const allowedHostsStr = process.env.ASAP_ALLOWED_HOSTS || '127.0.0.1,localhost,mentor-lms-learning-management-system.test';
        const allowedHosts = allowedHostsStr.split(',').map(h => h.trim());
        if (!allowedHosts.includes(url.hostname)) {
            console.error(`Host rejected: ${url.hostname}`);
            return false;
        }

        // 3. Validate port (from env or config)
        const allowedPortsStr = process.env.ASAP_ALLOWED_PORTS || '8001,80,443';
        const allowedPorts = allowedPortsStr.split(',').map(p => p.trim());
        const currentPort = url.port || (url.protocol === 'https:' ? '443' : '80');
        if (!allowedPorts.includes(currentPort)) {
            console.error(`Port rejected: ${currentPort}`);
            return false;
        }

        // 4. Validate path name pattern (must match ^/student/exam-attempts/\d+/take$)
        const pathRegex = /^\/student\/exam-attempts\/\d+\/take$/;
        if (!pathRegex.test(url.pathname)) {
            console.error(`Path rejected: ${url.pathname}`);
            return false;
        }

        // 5. Reject credentials
        if (url.username || url.password) {
            console.error('URL credentials validation failed.');
            return false;
        }

        // 6. Reject hash fragments
        if (url.hash) {
            console.error('URL hash fragment validation failed.');
            return false;
        }

        return true;
    } catch (e) {
        console.error('Target URL validation failed with error:', e);
        return false;
    }
}

async function handleDeepLink(urlStr: string) {
    const params = parseLaunchParams(urlStr);
    if (!params) {
        console.error('Could not parse launch params.');
        return;
    }

    const backendUrl = process.env.ASAP_API_URL || 'http://127.0.0.1:8001';
    console.log(`Performing handshake with backend: ${backendUrl}...`);

    const success = await SessionService.getInstance().bootstrap(
        params.bootstrapToken,
        params.attempt,
        params.signature,
        backendUrl
    );

    if (success) {
        const targetUrl = SessionService.getInstance().getTargetUrl();
        if (targetUrl && validateTargetUrl(targetUrl)) {
            console.log(`Handshake validated. Loading target: ${targetUrl}`);
            if (mainWindow) {
                mainWindow.loadURL(targetUrl);
            } else {
                createWindow(targetUrl);
            }
        } else {
            console.error(`Rejected target URL: ${targetUrl}`);
        }
    } else {
        console.error('Handshake failed.');
    }
}

const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
    app.quit();
} else {
    app.on('second-instance', (event, commandLine) => {
        if (mainWindow) {
            if (mainWindow.isMinimized()) {
                mainWindow.restore();
            }
            mainWindow.focus();

            const urlArg = commandLine.find(arg => arg.startsWith('asap://'));
            if (urlArg) {
                writeDeepLinkProof(urlArg);
                handleDeepLink(urlArg);
            }
        }
    });

    app.whenReady().then(() => {
        // Register custom protocol client
        if (process.defaultApp) {
            if (process.argv.length >= 2) {
                app.setAsDefaultProtocolClient('asap', process.execPath, [path.resolve(process.argv[1])]);
            }
        } else {
            app.setAsDefaultProtocolClient('asap');
        }

        // Detect cold-start deep link URL
        const urlArg = process.argv.find(arg => arg.startsWith('asap://'));
        if (urlArg) {
            writeDeepLinkProof(urlArg);
            handleDeepLink(urlArg);
        } else {
            createWindow();
        }

        app.on('activate', () => {
            if (BrowserWindow.getAllWindows().length === 0) {
                createWindow();
            }
        });
    });
}

/**
 * Initialize and apply window configurations, hooks, and loaders.
 */
function createWindow(targetUrl?: string) {
    const urlToLoad = targetUrl || 'http://127.0.0.1:8001/dashboard';
    const isDev = urlToLoad.includes('127.0.0.1') || urlToLoad.includes('localhost') || urlToLoad.includes('.test');
    
    mainWindow = new BrowserWindow({
        width: 1200,
        height: 800,
        fullscreen: !isDev,
        alwaysOnTop: !isDev,
        kiosk: !isDev,
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

    if (isDev) {
        mainWindow.webContents.openDevTools();
    }

    mainWindow.webContents.on('console-message', (event, level, message, line, sourceId) => {
        console.log(`[WebView Console] [Level ${level}] ${message} (Source: ${sourceId}:${line})`);
    });

    mainWindow.webContents.on('did-fail-load', (event, errorCode, errorDescription, validatedURL) => {
        console.error(`[WebView Load Fail] Code: ${errorCode}, Description: ${errorDescription}, URL: ${validatedURL}`);
    });

    // Set custom user agent identifying the secure client
    mainWindow.webContents.setUserAgent('ASAP-Secure-Client/1.0.0');

    // Apply security protections
    ScreenProtection.enable(mainWindow);
    WindowProtection.apply(mainWindow);
    WindowProtection.registerGlobalShortcuts();

    // Register IPC channels and setup client reactions
    IPCService.register((action: string, reason: string) => {
        console.log(`ASAP Action triggered: [${action}] - Reason: ${reason}`);
        if (mainWindow) {
            mainWindow.webContents.send('asap:action', action, reason);

            if (action === 'pause') {
                HeartbeatService.getInstance().setPaused(true);
            } else if (action === 'warn') {
                // Warning is passed to renderer overlay
            } else if (action === 'terminate') {
                LoggingService.log('session.terminated', 'critical', 'N/A');
                mainWindow.destroy();
                app.quit();
            }
        }
    });

    // Load initial target page
    mainWindow.loadURL(urlToLoad);

    mainWindow.on('closed', () => {
        mainWindow = null;
        HeartbeatService.getInstance().stop();
        WindowProtection.unregisterGlobalShortcuts();
    });
}

/**
 * Handle process crashes and execute secure session recovery checks.
 */
async function handleCrashAndRecovery(type: 'child' | 'render' | 'gpu', reason: string) {
    const session = SessionService.getInstance();
    const sessionId = session.getSessionId();
    const token = session.getToken();

    if (!sessionId || !token) {
        app.quit();
        return;
    }

    LoggingService.log('process.crashed', 'critical', 'N/A', {
        type: type,
        reason: reason
    });

    try {
        console.log(`Querying session status for recovery after ${type} crash...`);
        const response = await fetch(`${session.getBackendUrl()}/api/v1/asap/session/${sessionId}`, {
            method: 'GET',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json',
            }
        });

        if (!response.ok) {
            app.quit();
            return;
        }

        const data = (await response.json()) as any;
        if (data.status === 'success' && data.session && data.session.status !== 'terminated') {
            console.log('Session is authorized for resumption. Recreating window...');
            LoggingService.log('session.recovered', 'info', 'N/A', {
                session_id: sessionId
            });
            if (mainWindow) {
                mainWindow.destroy();
            }
            createWindow();
        } else {
            console.warn('Session has been terminated on server. Refusing recovery.');
            app.quit();
        }
    } catch (e) {
        console.error('Recovery check failed:', e);
        app.quit();
    }
}

// Bind process crash events
app.on('child-process-gone', (event, details) => {
    handleCrashAndRecovery('child', details.reason);
});

app.on('render-process-gone', (event, webContents, details) => {
    handleCrashAndRecovery('render', details.reason);
});

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') {
        app.quit();
    }
});
