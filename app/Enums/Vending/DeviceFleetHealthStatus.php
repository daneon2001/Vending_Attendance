<?php

namespace App\Enums\Vending;

enum DeviceFleetHealthStatus: string
{
    case PENDING = 'PENDING';
    case ONLINE = 'ONLINE';
    case DEGRADED = 'DEGRADED';
    case OFFLINE = 'OFFLINE';
    case SUSPENDED = 'SUSPENDED';
    case REVOKED = 'REVOKED';
    case RETIRED = 'RETIRED';
}
