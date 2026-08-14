<?php

namespace Modules\ASAP\Services;

use Illuminate\Support\Facades\Log;
use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\SecurityEvent;
use Modules\ASAP\Models\Incident;

class AuditLogger
{
    /**
     * Log session creation/start event.
     */
    public function logSessionStart(ExamSession $session, string $correlationId, array $context = []): void
    {
        $this->write('session.start', 'info', $correlationId, array_merge([
            'session_id' => $session->id,
            'user_id' => $session->user_id,
            'exam_id' => $session->exam_id,
            'device_id' => $session->device_id,
        ], $context));
    }

    /**
     * Log telemetry heartbeat received.
     */
    public function logHeartbeat(ExamSession $session, string $correlationId, array $context = []): void
    {
        $this->write('telemetry.heartbeat', 'info', $correlationId, array_merge([
            'session_id' => $session->id,
            'risk_score' => $session->risk_score,
            'status' => $session->status->value,
        ], $context));
    }

    /**
     * Log immediate security event.
     */
    public function logSecurityEvent(SecurityEvent $event, string $correlationId): void
    {
        $this->write('security.event', 'warning', $correlationId, [
            'event_id' => $event->id,
            'session_id' => $event->session_id,
            'event_code' => $event->event_code,
            'severity' => $event->severity,
            'category' => $event->category,
            'risk_delta' => $event->risk_delta,
        ]);
    }

    /**
     * Log security incidents.
     */
    public function logIncident(Incident $incident, string $correlationId, array $context = []): void
    {
        $this->write('security.incident', 'critical', $correlationId, array_merge([
            'incident_id' => $incident->id,
            'session_id' => $incident->session_id,
            'status' => $incident->status->value,
            'risk_score_snapshot' => $incident->risk_score_snapshot,
        ], $context));
    }

    /**
     * Log policy decisions made by the engine.
     */
    public function logPolicyDecision(ExamSession $session, string $action, string $reason, string $correlationId): void
    {
        $this->write('policy.decision', 'info', $correlationId, [
            'session_id' => $session->id,
            'action' => $action,
            'reason' => $reason,
            'current_risk_score' => $session->risk_score,
        ]);
    }

    /**
     * Write JSON logs via laravel logger asap_audit channel.
     */
    protected function write(string $event, string $level, string $correlationId, array $context): void
    {
        $logPayload = [
            'timestamp' => now()->toIso8601String(),
            'correlation_id' => $correlationId,
            'event' => $event,
            'context' => $context,
        ];

        Log::channel('asap_audit')->log($level, json_encode($logPayload));
    }
}
