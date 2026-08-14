# ADR-004: Electron IPC Security

## Status
Accepted

## Context
In Electron, the renderer process (the webview displaying the exam page) is potentially untrusted because it runs third-party front-end code (such as questions, media, or libraries). Allowing the renderer direct access to Node.js APIs or unrestricted IPC channels could lead to remote code execution or VM escape.

## Decision
We enforce a strict **IPC Security Contract** using preload scripts, context isolation, and sandboxing.
- `contextIsolation` is enabled, separating the execution contexts of the preload script and the website.
- `nodeIntegration` is disabled in the renderer.
- We expose a minimal, whitelisted API under the `window.asap` namespace with exactly four contract functions:
  * `bootstrap(token, sessionId)`: Performs initial handshake and setup.
  * `heartbeat()`: For triggering manual heartbeat/status updates.
  * `reportViolation(type, reason)`: For reporting OS-level violations.
  * `onAction(callback)`: Registers a callback to receive action instructions (e.g. warn, pause, terminate) from the main process.

## Renderer API Contract
```typescript
interface Window {
  asap: {
    bootstrap: (token: string, sessionId: string) => Promise<boolean>;
    onAction: (callback: (action: string, reason: string) => void) => void;
  }
}
```

## Consequences
- The exam page has zero access to Electron's internal main process capabilities or system resources.
- Front-end exploits cannot compromise the desktop operating system's environment.
