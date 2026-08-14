<?php

namespace Modules\ASAP\Services\Policy;

use Modules\ASAP\Models\SecurityEvent;

class DecayEngine
{
    /**
     * Calculate the decayed weight of a security event based on its occurred_at timestamp.
     */
    public function calculateDecayedWeight(SecurityEvent $event, int $cooldownWindowSeconds = 300): float
    {
        $occurredAt = $event->occurred_at;
        $now = now();

        if ($cooldownWindowSeconds <= 0) {
            return (float) $event->risk_delta;
        }

        if ($occurredAt->greaterThanOrEqualTo($now)) {
            return (float) $event->risk_delta;
        }

        $elapsedSeconds = $now->diffInSeconds($occurredAt);
        $elapsedSeconds = max(0.00, (float) $elapsedSeconds);

        // If the event is older than the cooldown/decay window, it decays completely to 0
        if ($elapsedSeconds >= $cooldownWindowSeconds) {
            return 0.00;
        }

        // Exponential decay: W = W0 * (1 - t/cooldown) or standard exponential decay
        // Let's use a linear-exponential decay relative to the cooldown window:
        // weight = base_weight * e^(-3 * elapsed / cooldown)
        $fraction = $elapsedSeconds / max(1, $cooldownWindowSeconds);
        $decayFactor = exp(-3 * $fraction);

        return round($event->risk_delta * $decayFactor, 2);
    }
}
