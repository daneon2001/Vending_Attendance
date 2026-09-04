<?php

namespace App\Enums\Vending;

enum AttendanceAuthorizationResult: string
{
    case AUTHORIZED = 'AUTHORIZED';
    case DENIED = 'DENIED';
    case UNVERIFIABLE = 'UNVERIFIABLE';
}
