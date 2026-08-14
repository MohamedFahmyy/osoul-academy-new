<?php

namespace Modules\ASAP\Services;

use Modules\ASAP\Exceptions\AsapException;
use Modules\ASAP\Events\TelemetryReceived;
use Modules\ASAP\Models\ExamSession;

class TelemetryService
{
    public function __construct(
        protected SessionService $sessionService,
        protected PolicyEngineInterface $policyEngine,
        protected ASAPCacheService $cacheService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Process a client telemetry heartbeat tick.
     *
     * @throws AsapException
     */
    public function processHeartbeat(array $data, string $correlationId): ExamSession
    {
        $startTime = microtime(true);

        // 1. Verify signature and authenticity of the request
        $session = $this->sessionService->verifySessionSignature(
            $data['session_id'],
            $data['session_key_id'],
            $data['signature'],
            [
                'session_id' => $data['session_id'],
                'session_key_id' => $data['session_key_id'],
                'payload' => $data['payload'],
            ]
        );

        // Nonce Replay Check (TTL = 300s)
        $nonce = $data['payload']['nonce'] ?? null;
        if ($nonce) {
            if ($this->cacheService->hasNonce($session->id, $nonce)) {
                throw new AsapException('ASAP-1009', 'Replay attack detected. Nonce already used.', 400);
            }
            $this->cacheService->putNonce($session->id, $nonce, 300);
        }

        // Sequence Rollback Check
        $sequence = $data['payload']['sequence_number'] ?? null;
        if ($sequence !== null) {
            $lastSequence = $this->cacheService->getLastSequence($session->id);
            if ($lastSequence !== null && $sequence <= $lastSequence) {
                throw new AsapException('ASAP-1010', 'Sequence number rollback detected.', 400);
            }
            $this->cacheService->putLastSequence($session->id, $sequence);
        }

        // Timestamp Freshness Check
        $timestamp = $data['payload']['timestamp'] ?? null;
        if ($timestamp !== null) {
            if (abs(now()->timestamp - $timestamp) > 300) {
                throw new AsapException('ASAP-1011', 'Heartbeat timestamp is out of acceptable window.', 400);
            }
        }

        // Update Device last_seen_at
        if ($session->device) {
            $session->device->update(['last_seen_at' => now()]);
        }

        // 2. Dispatch domain event to store telemetry asynchronously (Queued)
        event(new TelemetryReceived($session, $data['payload'], $correlationId));

        // 3. Process time-decay and evaluate current risk/action synchronously
        $this->policyEngine->applyDecay($session);

        // Refresh model from database
        $session->refresh();

        // 4. Update the high-speed Redis session cache state
        $this->cacheService->putSessionState($session);

        // 5. Auditing
        $this->auditLogger->logHeartbeat($session, $correlationId, [
            'is_focused' => $data['payload']['is_focused'],
            'is_fullscreen' => $data['payload']['is_fullscreen'],
        ]);

        // 6. Record Prometheus metrics if Monitoring module is loaded
        if (app()->bound(\Modules\Monitoring\Services\MetricsCollectorService::class)) {
            $duration = microtime(true) - $startTime;
            $collector = app(\Modules\Monitoring\Services\MetricsCollectorService::class);
            $collector->increment('asap_heartbeat_total');
            $collector->increment('asap_telemetry_latency_seconds_sum', (int) ($duration * 1000));
        }

        return $session;
    }
}
