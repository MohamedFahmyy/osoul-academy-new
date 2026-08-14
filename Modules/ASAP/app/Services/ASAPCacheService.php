<?php

namespace Modules\ASAP\Services;

use Illuminate\Support\Facades\Cache;
use Modules\ASAP\Models\ExamSession;

class ASAPCacheService
{
    /**
     * Get the cache store instance.
     */
    protected function getStore()
    {
        try {
            if (!class_exists('Redis')) {
                return Cache::store();
            }
            return Cache::store('redis');
        } catch (\Throwable $e) {
            return Cache::store();
        }
    }

    /**
     * Store or update session state in cache.
     */
    public function putSessionState(ExamSession $session): void
    {
        $state = [
            'id' => $session->id,
            'risk' => (float) $session->risk_score,
            'status' => $session->status->value,
            'device' => $session->device ? $session->device->uuid : '',
            'policy' => $session->policy_id,
            'expires_at' => $session->expires_at ? $session->expires_at->toIso8601String() : null,
            'last_heartbeat' => now()->toIso8601String(),
        ];

        // Cache session state for 24 hours
        $this->getStore()->put("asap:session:{$session->id}", $state, 86400);
    }

    /**
     * Get session state from cache.
     */
    public function getSessionState(string $sessionId): ?array
    {
        return $this->getStore()->get("asap:session:{$sessionId}");
    }

    /**
     * Remove session state from cache.
     */
    public function forgetSessionState(string $sessionId): void
    {
        $this->getStore()->forget("asap:session:{$sessionId}");
    }

    /**
     * Cache device blacklist status.
     */
    public function blacklistDevice(string $deviceUuid): void
    {
        $this->getStore()->put("asap:device:blacklist:{$deviceUuid}", true, 86400 * 30);
    }

    /**
     * Check if device is blacklisted in cache.
     */
    public function isDeviceBlacklisted(string $deviceUuid): bool
    {
        return (bool) $this->getStore()->get("asap:device:blacklist:{$deviceUuid}");
    }

    /**
     * Check if a nonce exists in cache.
     */
    public function hasNonce(string $sessionId, string $nonce): bool
    {
        return (bool) $this->getStore()->get("asap:nonce:{$sessionId}:{$nonce}");
    }

    /**
     * Store nonce in cache.
     */
    public function putNonce(string $sessionId, string $nonce, int $seconds = 300): void
    {
        $this->getStore()->put("asap:nonce:{$sessionId}:{$nonce}", true, $seconds);
    }

    /**
     * Get the last processed sequence number for a session.
     */
    public function getLastSequence(string $sessionId): ?int
    {
        $val = $this->getStore()->get("asap:session:{$sessionId}:sequence");
        return $val !== null ? (int) $val : null;
    }

    /**
     * Store the last processed sequence number for a session.
     */
    public function putLastSequence(string $sessionId, int $sequence): void
    {
        $this->getStore()->put("asap:session:{$sessionId}:sequence", $sequence, 86400);
    }
}
