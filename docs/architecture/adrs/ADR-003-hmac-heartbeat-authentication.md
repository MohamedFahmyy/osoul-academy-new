# ADR-003: HMAC Heartbeat Authentication

## Status
Accepted

## Context
Once the desktop application has bootstrapped and acquired the session keys, it must communicate telemetry and security violations back to the server. These subsequent requests cannot use standard web session cookies or generic auth headers because the request originates from a separate process (Electron main) that does not share the browser's cookie jar.

## Decision
We utilize the negotiated symmetric **Session Key** to sign all telemetry and event payloads using Hash-based Message Authentication Codes (HMAC).
- Every request to `/api/v1/asap/telemetry/heartbeat` and `/api/v1/asap/event` contains a signature header: `X-ASAP-Signature`.
- The signature is calculated as `hash_hmac('sha256', json_encode($payload), $rawSessionKey)`.
- The server validates the signature before processing the telemetry. If valid, the session is authenticated cryptographically.
- These endpoints bypass the standard `auth:sanctum` middleware, allowing them to remain completely independent of user password or token rotation.

## Consequences
- The communication channel is secure and tamper-proof.
- Network eavesdroppers cannot alter telemetry or spoof heartbeat packets without knowing the session key.
- The server database only stores the encrypted version of the session key, ensuring that the raw key only exists in server memory and the client's transient process memory.
