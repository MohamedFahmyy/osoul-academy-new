<?php

namespace Modules\ASAP\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\ASAP\Events\IncidentOpened;
use Modules\ASAP\Models\Evidence;
use Modules\ASAP\Models\Telemetry;
use Modules\ASAP\Models\SecurityEvent;

class GenerateIncidentEvidence implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(IncidentOpened $event): void
    {
        $incident = $event->incident;
        $session = $incident->session;

        // Fetch last 5 telemetries
        $telemetries = Telemetry::where('session_id', $session->id)
            ->orderBy('recorded_at', 'desc')
            ->limit(5)
            ->get();

        // Fetch last 5 security events
        $securityEvents = SecurityEvent::where('session_id', $session->id)
            ->orderBy('occurred_at', 'desc')
            ->limit(5)
            ->get();

        Evidence::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'incident_id' => $incident->id,
            'telemetry_snapshot' => $telemetries->toArray(),
            'event_snapshot' => $securityEvents->toArray(),
            'ip_address' => request()->ip() ?: '127.0.0.1',
            'client_version' => request()->header('X-Client-Version') ?: '1.0.0',
            'policy_version' => $session->policy->name,
            'risk_engine_version' => '1.0.0',
            'sdk_version' => request()->header('X-SDK-Version') ?: '1.0.0',
            'os_version' => $session->device->operating_system ?: 'unknown',
            'decision' => $session->status->value,
            'decision_source' => 'server_policy_engine',
            'engine_build' => 'asap_engine_1.0.0',
            'correlation_snapshot' => ['correlation_id' => $event->correlationId],
            'decision_reason' => 'Incident triggered with risk score ' . $incident->risk_score_snapshot,
        ]);
    }
}
