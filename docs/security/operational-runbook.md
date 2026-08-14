# Operational Runbook - Secure Assessment Platform (ASAP)

This runbook outlines operational procedures and incident response for the secure exam platform.

## 1. Incident Response & Troubleshooting

### Scenario A: Redis Cache / Session Storage Fails
- **Impact**: Active sessions cannot be fetched; heartbeats fail to update, causing potential student lockouts.
- **Action**: 
  1. The database serves as the secondary fallback for active session checks if Redis goes down.
  2. If Redis is down, restart the service:
     - Linux: `sudo systemctl restart redis-server`
     - Windows/Laragon: Restart Redis from the Laragon panel.
  3. Clear cache if session data gets corrupted: `php artisan cache:clear`.

### Scenario B: Queue Worker / Telemetry Queue Stops
- **Impact**: Telemetry processing and security policy evaluations are delayed, preventing real-time warnings or terminations.
- **Action**:
  1. Check queue worker status: `php artisan queue:status`
  2. Restart queue workers: `php artisan queue:restart`
  3. Ensure supervisor is running the workers in production.

### Scenario C: Heartbeat Packet Loss & Latency
- **Impact**: Students with unstable connections get disconnected, triggering false-positive violations.
- **Action**:
  1. Electron's offline queue buffers telemetry locally for up to 60 seconds during connection drops.
  2. Adjust policy thresholds in Admin Panel: Increase the allowed missed heartbeats count from 3 to 5 for remote students.
  3. Check the telemetry processor load in Laravel Pail: `php artisan pail`.

---

## 2. Key Rotation & Expiration Policies

### Application Key Rotation
- **Frequency**: Annually or in case of breach.
- **Impact**: Rotating the Laravel `APP_KEY` will invalidate existing encrypted session keys in the database.
- **Procedure**:
  1. Do not rotate keys during active exam windows.
  2. Run `php artisan key:generate` to generate a new key.
  3. Active sessions will be terminated; students must restart the desktop client.

### Session Lifetime Policy
- **Handshake Expiry**: 2 minutes. If a student stays on the launch page without opening Electron, the token expires.
- **Active Session Expiry**: Default is 4 hours. Controlled via `expires_at` in the `asap_sessions` table.

---

## 3. Desktop Application Updates (Electron)

### Auto-Update Mechanism
- Electron uses `electron-updater` checking against a secure release bucket.
- **Procedure**:
  1. Build the updated installer: `npm run build` followed by packager scripts.
  2. Upload release files (e.g. `.exe`, `latest.yml`) to the configured release repository.
  3. During startup, Electron verifies signature, downloads updates in the background, and prompts for restart.
- **Rollback**:
  1. To rollback, update the `latest.yml` version to point to the previous stable release.
  2. Electron clients will automatically download the older version and downgrade on the next launch.
