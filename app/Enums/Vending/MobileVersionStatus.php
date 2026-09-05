<?php

namespace App\Enums\Vending;

enum MobileVersionStatus: string
{
    case CURRENT = 'CURRENT';
    case UPDATE_AVAILABLE = 'UPDATE_AVAILABLE';
    case UPDATE_REQUIRED = 'UPDATE_REQUIRED';
    case UNSUPPORTED = 'UNSUPPORTED';
    case UNKNOWN = 'UNKNOWN';
}
