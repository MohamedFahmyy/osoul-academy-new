<?php

namespace Modules\ASAP\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\ASAP\Events\TelemetryReceived;
use Modules\ASAP\Events\IncidentOpened;
use Modules\ASAP\Listeners\StoreTelemetryData;
use Modules\ASAP\Listeners\GenerateIncidentEvidence;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        TelemetryReceived::class => [
            StoreTelemetryData::class,
        ],
        IncidentOpened::class => [
            GenerateIncidentEvidence::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = false;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
