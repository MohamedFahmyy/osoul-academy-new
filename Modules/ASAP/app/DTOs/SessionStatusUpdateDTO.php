<?php

namespace Modules\ASAP\DTOs;

class SessionStatusUpdateDTO
{
    public function __construct(
        public readonly string $status,
        public readonly ?float $riskScore = null,
        public readonly string $timestamp
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status: $data['status'],
            riskScore: isset($data['risk_score']) ? (float) $data['risk_score'] : null,
            timestamp: $data['timestamp'] ?? now()->toIso8601String()
        );
    }
}
