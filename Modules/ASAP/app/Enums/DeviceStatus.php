<?php

namespace Modules\ASAP\Enums;

enum DeviceStatus: string
{
    case UNKNOWN = 'unknown';
    case PENDING = 'pending';
    case VERIFIED = 'verified';
    case TRUSTED = 'trusted';
    case SUSPENDED = 'suspended';
    case REVOKED = 'revoked';
}
