<?php

namespace Modules\ASAP\Enums;

enum PolicyAction: string
{
    case ALLOW = 'allow';
    case WARN = 'warn';
    case PAUSE = 'pause';
    case TERMINATE = 'terminate';
}
