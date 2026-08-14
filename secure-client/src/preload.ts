import { contextBridge, ipcRenderer } from 'electron';

contextBridge.exposeInMainWorld('asap', {
    /**
     * Bootstrap the secure assessment session from the renderer page.
     */
    bootstrap: (token: string, sessionId: string, backendUrl?: string): Promise<boolean> => {
        return ipcRenderer.invoke('asap:bootstrap', token, sessionId, backendUrl);
    },

    /**
     * Report a client-detected security violation to the main process.
     */
    reportViolation: (eventCode: string, severity: string, category: string, payload: any): Promise<boolean> => {
        return ipcRenderer.invoke('asap:event', eventCode, severity, category, payload);
    },

    /**
     * Listen for action triggers issued from the main process.
     */
    onAction: (callback: (action: string, reason: string) => void): void => {
        ipcRenderer.on('asap:action', (event, action: string, reason: string) => callback(action, reason));
    }
});
