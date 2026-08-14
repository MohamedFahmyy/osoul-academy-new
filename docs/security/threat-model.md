# Threat Model - Secure Exam Environment (ASAP)

This document maps potential threat vectors against the implemented mitigations in ASAP v1.0.

## Threat Analysis Matrix

| Threat Vector | Description | Implemented Mitigation |
| :--- | :--- | :--- |
| **Replay Attack** | Intercepting a handshake payload and re-sending it to hijack the key. | Ephemeral Bootstrap Token (One-time use, immediately consumed upon validation, strict 2-minute expiration). |
| **Token Theft** | Read-only database leakage exposes active bootstrap tokens. | Tokens are hashed using SHA-256 in the database. The plaintext token is never stored. |
| **User-Agent Spoofing** | Spoofing `ASAP-Secure-Client/1.0.0` in a normal browser to access exams. | User-Agent is only for logging. Authentication requires a complete, cryptographically verified session handshake and signing. |
| **Deep Link Injection** | Forcing Electron to navigate to malicious sites via protocol manipulation. | The main process filters all `asap://` URLs using an allowlist of permitted hosts (`localhost`, `127.0.0.1`, etc.) and path prefixes. |
| **Network Interruption** | Network dropouts resulting in false-positive security termination. | Offline Telemetry Queue in Electron cache allows local buffering of heartbeats; recovers state when connection resumes. |
| **Renderer Crash** | Maliciously crashing the renderer to escape kiosk mode. | The main process detects window/renderer crash events and triggers a secure lock or application exit. |
| **IPC Injection** | Accessing sensitive Electron main APIs from the browser context. | Context isolation and sandboxing are enabled. Preload script exposes a strictly whitelisted 4-channel API under `window.asap`. |
| **Popup Abuse** | Opening child windows or developer tools to bypass constraints. | Electron main overrides `new-window` and window creation events, force-preventing window popups and blocking DevTools. |
| **Symmetric Key Compromise** | Key extraction from server database. | Session keys are stored encrypted using Laravel's application key (`AES-256-CBC`), decrypted only in memory. |

---

## Trust Boundaries

```
[ Browser Context (Renderer) ]
             |
   (Context Isolated IPC)
             |
[ Electron Main Process ]  <====== (Deep Link Protocols) ======  [ Operating System ]
             |
     (HMAC-Signed HTTP)
             |
[ Laravel Backend App ]    <====== (AES-256 Encrypted) ========  [ Database (SQLite/MySQL) ]
```
