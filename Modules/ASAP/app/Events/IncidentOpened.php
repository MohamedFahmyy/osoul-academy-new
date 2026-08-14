<?php

namespace Modules\ASAP\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\ASAP\Models\Incident;

class IncidentOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Incident $incident,
        public readonly string $correlationId
    ) {}
}
