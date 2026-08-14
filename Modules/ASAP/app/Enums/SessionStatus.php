<?php

namespace Modules\ASAP\Enums;

enum SessionStatus: string
{
    case CREATED = 'created';
    case AUTHENTICATED = 'authenticated';
    case ENVIRONMENT_VALIDATION = 'environment_validation';
    case READY = 'ready';
    case RUNNING = 'running';
    case WARNING = 'warning';
    case PAUSED = 'paused';
    case RESUMED = 'resumed';
    case SUBMITTED = 'submitted';
    case COMPLETED = 'completed';
    case ARCHIVED = 'archived';
    case TERMINATED = 'terminated';
}
