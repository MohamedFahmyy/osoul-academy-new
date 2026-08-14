<?php

namespace Modules\ASAP\Enums;

enum IncidentStatus: string
{
    case OPEN = 'open';
    case UNDER_REVIEW = 'under_review';
    case RESOLVED = 'resolved';
    case DISMISSED = 'dismissed';
}
