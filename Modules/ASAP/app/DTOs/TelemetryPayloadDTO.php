<?php

namespace Modules\ASAP\DTOs;

class TelemetryPayloadDTO
{
    /**
     * @param array{is_focused: bool, is_fullscreen: bool, display_count: int, window_state: string} $telemetry
     */
    public function __construct(
        public readonly string $timestamp,
        public readonly string $clientVersion,
        public readonly array $telemetry
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            timestamp: $data['timestamp'] ?? now()->toIso8601String(),
            clientVersion: $data['client_version'] ?? '1.0.0',
            telemetry: [
                'is_focused' => (bool) ($data['telemetry']['is_focused'] ?? true),
                'is_fullscreen' => (bool) ($data['telemetry']['is_fullscreen'] ?? true),
                'display_count' => (int) ($data['telemetry']['display_count'] ?? 1),
                'window_state' => (string) ($data['telemetry']['window_state'] ?? 'fullscreen'),
            ]
        );
    }
}
