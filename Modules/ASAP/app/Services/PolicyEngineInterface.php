<?php

namespace Modules\ASAP\Services;

use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\SecurityEvent;

interface PolicyEngineInterface
{
    /**
     * Ingest an incoming security event and update the session state.
     */
    public function ingestEvent(ExamSession $session, SecurityEvent $event): void;

    /**
     * Calculate the current cumulative risk score for a session.
     */
    public function calculateRisk(ExamSession $session): float;

    /**
     * Apply time-decay to past security events.
     */
    public function applyDecay(ExamSession $session): void;

    /**
     * Determine the required policy action based on the session's risk score.
     * Returns: 'allow', 'warn', 'pause', or 'terminate'.
     */
    public function determineAction(ExamSession $session): string;

    /**
     * Record the final policy engine decision to the session and log incident.
     */
    public function recordDecision(ExamSession $session, string $action, string $reason): void;

    /**
     * Check if an event should be throttled based on its cooldown window configuration.
     */
    public function shouldThrottleEvent(ExamSession $session, string $eventCode): bool;
}
