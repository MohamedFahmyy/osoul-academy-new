<?php

namespace Modules\ASAP\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\ASAP\Events\TelemetryReceived;
use Modules\ASAP\Models\Telemetry;

class StoreTelemetryData implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(TelemetryReceived $event): void
    {
        Telemetry::create([
            'session_id' => $event->session->id,
            'telemetry_schema_version' => '1.0.0',
            'payload' => $event->payload,
            'recorded_at' => now(),
        ]);
    }
}
