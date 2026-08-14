<?php

namespace Modules\ASAP\Services\Policy;

use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\SecurityEvent;

class CooldownEngine
{
    /**
     * Check if an event should be throttled based on its cooldown configuration.
     */
    public function shouldThrottle(ExamSession $session, string $eventCode, int $cooldownWindowSeconds): bool
    {
        if ($cooldownWindowSeconds <= 0) {
            return false;
        }

        // Fetch the last recorded event of this type
        $lastEvent = SecurityEvent::where('session_id', $session->id)
            ->where('event_code', $eventCode)
            ->orderBy('occurred_at', 'desc')
            ->first();

        if (!$lastEvent) {
            return false;
        }

        $elapsedSeconds = now()->diffInSeconds($lastEvent->occurred_at);

        return $elapsedSeconds < $cooldownWindowSeconds;
    }
}
