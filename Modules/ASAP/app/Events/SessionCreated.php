<?php

namespace Modules\ASAP\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\ASAP\Models\ExamSession;

class SessionCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ExamSession $session,
        public readonly string $correlationId
    ) {}
}
