<?php

namespace Modules\ASAP\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\ASAP\Models\ExamSession;

class SessionKeyService
{
    /**
     * Generate a new ephemeral session key.
     */
    public function generateKey(ExamSession $session): array
    {
        $rawKey = Str::random(32);
        $keyId = Str::uuid()->toString();
        $keyHash = hash('sha256', $rawKey);
        $keyEncrypted = Crypt::encryptString($rawKey);

        return [
            'session_key_id' => $keyId,
            'session_key_hash' => $keyHash,
            'session_key_encrypted' => $keyEncrypted,
            'raw_key' => $rawKey,
        ];
    }

    /**
     * Rotate the ephemeral session key.
     */
    public function rotateKey(ExamSession $session): array
    {
        $keys = $this->generateKey($session);

        $session->update([
            'session_key_id' => $keys['session_key_id'],
            'session_key_hash' => $keys['session_key_hash'],
            'session_key_encrypted' => $keys['session_key_encrypted'],
            'rotated_at' => now(),
        ]);

        return $keys;
    }

    /**
     * Decrypt the session key.
     */
    public function decryptKey(ExamSession $session): string
    {
        return Crypt::decryptString($session->session_key_encrypted);
    }

    /**
     * Verify HMAC signature of the payload.
     */
    public function verifySignature(string $rawKey, array $payload, string $signature): bool
    {
        $normalized = $this->normalizePayload($payload);
        $expectedSignature = hash_hmac('sha256', json_encode($normalized), $rawKey);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Revoke the session key.
     */
    public function revokeKey(ExamSession $session): void
    {
        $session->update([
            'session_key_id' => null,
            'session_key_hash' => null,
            'session_key_encrypted' => null,
            'rotated_at' => null,
        ]);
    }

    /**
     * Check if the session is expired.
     */
    public function isExpired(ExamSession $session): bool
    {
        return $session->expires_at && $session->expires_at->isPast();
    }

    /**
     * Normalize the payload recursively by keys.
     */
    protected function normalizePayload(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalizePayload($value);
            }
        }
        return $data;
    }
}
