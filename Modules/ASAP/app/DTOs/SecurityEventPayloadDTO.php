<?php

namespace Modules\ASAP\DTOs;

class SecurityEventPayloadDTO
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $timestamp,
        public readonly string $eventCode,
        public readonly array $payload,
        public readonly string $severity = 'warning',
        public readonly string $source = 'client',
        public readonly string $category = 'system',
        public readonly int $clientSequence = 0,
        public readonly ?string $correlationId = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['event_id'] ?? \Illuminate\Support\Str::uuid()->toString(),
            timestamp: $data['timestamp'] ?? now()->toIso8601String(),
            eventCode: $data['event_code'] ?? 'GENERIC_SECURITY_EVENT',
            payload: $data['payload'] ?? [],
            severity: $data['severity'] ?? 'warning',
            source: $data['source'] ?? 'client',
            category: $data['category'] ?? 'system',
            clientSequence: (int) ($data['client_sequence'] ?? 0),
            correlationId: $data['correlation_id'] ?? null
        );
    }
}
