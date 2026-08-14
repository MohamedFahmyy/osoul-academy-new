<?php

namespace Modules\ASAP\Services\Policy;

use Modules\ASAP\Models\ExamSession;

class RiskCalculator
{
    public function __construct(
        protected DecayEngine $decayEngine
    ) {}

    /**
     * Compute cumulative decayed risk score for the session.
     */
    public function calculate(ExamSession $session): float
    {
        $session->loadMissing('policy.rules');
        $policy = $session->policy;
        if (!$policy) {
            return 0.00;
        }

        $events = $session->securityEvents()->orderBy('occurred_at', 'desc')->get();
        $cumulativeRisk = 0.00;

        foreach ($events as $event) {
            $rule = $policy->rules->where('event_code', $event->event_code)->first();
            $cooldown = $rule ? $rule->cooldown_window : 300;

            // Apply time decay on risk delta
            $decayedWeight = $this->decayEngine->calculateDecayedWeight($event, $cooldown);
            $cumulativeRisk += $decayedWeight;
        }

        // Clamp risk score between 0.00 and 100.00
        return (float) min(100.00, max(0.00, round($cumulativeRisk, 2)));
    }
}
