<?php

namespace Modules\ASAP\Services;

use Carbon\Carbon;

class BootstrapToken
{
    /**
     * Generate a signed bootstrap token.
     */
    public static function generate(string $sessionId, int $attemptId, int $expirationMinutes = 2): string
    {
        $version = config('asap.active_key_version', 2);
        $key = config("asap.keys.{$version}");

        if (!$key) {
            throw new \Exception("Signing key for version {$version} not found.");
        }

        $payload = [
            'kid' => $version,
            'sid' => $sessionId,
            'aid' => $attemptId,
            'exp' => Carbon::now()->addMinutes($expirationMinutes)->timestamp,
        ];

        $json = json_encode($payload);
        $base64 = base64_encode($json);
        $signature = hash_hmac('sha256', $base64, $key);

        return $base64 . '.' . $signature;
    }

    /**
     * Decode and verify a bootstrap token.
     */
    public static function decode(string $token): ?object
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$base64, $signature] = $parts;
        
        $json = base64_decode($base64);
        $payload = json_decode($json);
        if (!$payload || !isset($payload->kid)) {
            return null;
        }

        $key = config("asap.keys.{$payload->kid}");
        if (!$key) {
            return null; // Key version not recognized/supported
        }

        $expectedSignature = hash_hmac('sha256', $base64, $key);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Add helper for expires_at Carbon comparison
        $payload->expires_at = Carbon::createFromTimestamp($payload->exp);

        return $payload;
    }
}
