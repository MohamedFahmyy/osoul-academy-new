<?php

namespace Modules\ASAP\Services;

use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\SecurityEvent;
use Modules\ASAP\Services\Policy\RiskCalculator;
use Modules\ASAP\Services\Policy\DecayEngine;
use Modules\ASAP\Services\Policy\CooldownEngine;
use Modules\ASAP\Services\Policy\DecisionEngine;
use Modules\ASAP\Enums\SessionStatus;
use Modules\ASAP\Events\PolicyDecisionMade;

class DefaultPolicyEngine implements PolicyEngineInterface
{
    public function __construct(
        protected RiskCalculator $riskCalculator,
        protected DecayEngine $decayEngine,
        protected CooldownEngine $cooldownEngine,
        protected DecisionEngine $decisionEngine,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Ingest an incoming security event and update the session state.
     */
    public function ingestEvent(ExamSession $session, SecurityEvent $event): void
    {
        $session->loadMissing('policy.rules');
        $rule = $session->policy->rules->where('event_code', $event->event_code)->first();

        // 1. Determine base weight/risk delta of this event
        $weight = $rule ? (float) $rule->weight : 10.00;
        $event->update([
            'risk_delta' => $weight,
            'processed_at' => now(),
            'policy_action' => $rule ? $rule->action->value : 'warn',
        ]);

        // 2. Re-calculate dynamic risk score of the session
        $this->applyDecay($session);
    }

    /**
     * Calculate the current cumulative risk score for a session.
     */
    public function calculateRisk(ExamSession $session): float
    {
        return $this->riskCalculator->calculate($session);
    }

    /**
     * Apply time-decay to past security events.
     */
    public function applyDecay(ExamSession $session): void
    {
        $startTime = microtime(true);
        $newScore = $this->calculateRisk($session);
        $session->update(['risk_score' => $newScore]);

        // Evaluate action and execute decision flow
        $action = $this->determineAction($session);
        $this->recordDecision($session, $action, "Time-decay and active events evaluation.");

        // Record Prometheus metrics if Monitoring module is loaded
        if (app()->bound(\Modules\Monitoring\Services\MetricsCollectorService::class)) {
            $duration = microtime(true) - $startTime;
            app(\Modules\Monitoring\Services\MetricsCollectorService::class)->increment('asap_policy_decision_duration_seconds_sum', (int) ($duration * 1000));
        }
    }

    /**
     * Determine the required policy action based on the session's risk score.
     */
    public function determineAction(ExamSession $session): string
    {
        return $this->decisionEngine->resolveAction($session, $session->risk_score);
    }

    /**
     * Record the final policy decision.
     */
    public function recordDecision(ExamSession $session, string $action, string $reason): void
    {
        $statusMap = [
            'warn' => SessionStatus::WARNING,
            'pause' => SessionStatus::PAUSED,
            'terminate' => SessionStatus::TERMINATED,
            'allow' => SessionStatus::RUNNING,
        ];

        $targetStatus = $statusMap[$action] ?? SessionStatus::RUNNING;

        // Only update if state changes to prevent infinite loops
        if ($session->status !== $targetStatus) {
            // Once session is terminated, it cannot transition back to running
            if ($session->status === SessionStatus::TERMINATED) {
                return;
            }

            $session->update(['status' => $targetStatus->value]);

            $correlationId = request()->header('X-Correlation-ID') ?: '';

            if (in_array($targetStatus, [SessionStatus::WARNING, SessionStatus::PAUSED, SessionStatus::TERMINATED])) {
                $incident = \Modules\ASAP\Models\Incident::create([
                    'id' => \Illuminate\Support\Str::uuid()->toString(),
                    'session_id' => $session->id,
                    'status' => \Modules\ASAP\Enums\IncidentStatus::OPEN->value,
                    'risk_score_snapshot' => $session->risk_score,
                ]);

                // Audit Log Incident
                $this->auditLogger->logIncident($incident, $correlationId);

                // Dispatch IncidentOpened to compile evidence asynchronously
                event(new \Modules\ASAP\Events\IncidentOpened($incident, $correlationId));
            }

            // Audit Log Policy Decision
            $this->auditLogger->logPolicyDecision($session, $action, $reason, $correlationId);

            // Increment policy decision metric
            if (app()->bound(\Modules\Monitoring\Services\MetricsCollectorService::class)) {
                app(\Modules\Monitoring\Services\MetricsCollectorService::class)->increment("asap_policy_{$action}_total");
            }

            // Dispatch decision made event
            event(new PolicyDecisionMade($session, $action, $reason, $correlationId));
        }
    }

    /**
     * Check if an event should be throttled based on its cooldown window.
     */
    public function shouldThrottleEvent(ExamSession $session, string $eventCode): bool
    {
        $session->loadMissing('policy.rules');
        $rule = $session->policy->rules->where('event_code', $eventCode)->first();
        $cooldown = $rule ? $rule->cooldown_window : 0;

        return $this->cooldownEngine->shouldThrottle($session, $eventCode, $cooldown);
    }
}
