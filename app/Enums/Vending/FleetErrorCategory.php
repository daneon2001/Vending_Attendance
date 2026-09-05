<?php

namespace App\Enums\Vending;

enum FleetErrorCategory: string
{
    case NETWORK = 'NETWORK';
    case AUTH = 'AUTH';
    case CLOCK = 'CLOCK';
    case MANIFEST = 'MANIFEST';
    case SQLITE = 'SQLITE';
    case GPS = 'GPS';
    case GEOFENCE = 'GEOFENCE';
    case ATTENDANCE = 'ATTENDANCE';
    case UPDATE = 'UPDATE';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
