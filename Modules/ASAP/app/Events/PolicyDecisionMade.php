<?php

namespace Modules\ASAP\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\ASAP\Models\ExamSession;

class PolicyDecisionMade
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ExamSession $session,
        public readonly string $action,
        public readonly string $reason,
        public readonly string $correlationId
    ) {}
}
