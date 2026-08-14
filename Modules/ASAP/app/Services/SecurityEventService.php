<?php

namespace Modules\ASAP\Services;

use Modules\ASAP\Exceptions\AsapException;
use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\SecurityEvent;

class SecurityEventService
{
    public function __construct(
        protected SessionService $sessionService,
        protected PolicyEngineInterface $policyEngine,
        protected ASAPCacheService $cacheService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Report an immediate security violation event from the client.
     *
     * @throws AsapException
     */
    public function reportEvent(array $data, string $correlationId): ExamSession
    {
        // 1. Verify signature and authenticity of the request
        $signaturePayload = [
            'session_id' => $data['session_id'],
            'session_key_id' => $data['session_key_id'],
            'event_uuid' => $data['event_uuid'],
            'event_code' => $data['event_code'],
            'payload' => $data['payload'] ?? null,
            'severity' => $data['severity'],
            'source' => $data['source'],
            'category' => $data['category'],
            'client_sequence' => (int) $data['client_sequence'],
            'occurred_at' => $data['occurred_at']
        ];

        $session = $this->sessionService->verifySessionSignature(
            $data['session_id'],
            $data['session_key_id'],
            $data['signature'],
            $signaturePayload
        );

        // 2. Idempotency Check: check if event was already processed
        $existingEvent = SecurityEvent::find($data['event_uuid']);
        if ($existingEvent) {
            return $session; // Return immediately (idempotent match)
        }

        // 3. Cooldown Throttling check
        $isThrottled = $this->policyEngine->shouldThrottleEvent($session, $data['event_code']);

        // 4. Create the SecurityEvent
        $event = SecurityEvent::create([
            'id' => $data['event_uuid'],
            'session_id' => $session->id,
            'event_code' => $data['event_code'],
            'payload' => $data['payload'] ?? null,
            'severity' => $data['severity'],
            'source' => $data['source'],
            'category' => $data['category'],
            'client_sequence' => $data['client_sequence'],
            'correlation_id' => $correlationId,
            'occurred_at' => $data['occurred_at'],
        ]);

        if ($isThrottled) {
            // Log as throttled (zero risk weight)
            $event->update([
                'risk_delta' => 0.00,
                'processed_at' => now(),
                'policy_action' => 'throttled'
            ]);
        } else {
            // Ingest to update risk score & state
            $this->policyEngine->ingestEvent($session, $event);
        }

        $session->refresh();

        // 5. Update Redis Session cache
        $this->cacheService->putSessionState($session);

        // 6. Audit Logging
        $this->auditLogger->logSecurityEvent($event, $correlationId);

        return $session;
    }
}
