<?php

namespace App\Enums\Vending;

enum AttendanceGeofenceResult: string
{
    case INSIDE = 'INSIDE';
    case OUTSIDE = 'OUTSIDE';
    case UNCERTAIN = 'UNCERTAIN';
    case NOT_EVALUATED = 'NOT_EVALUATED';

    public static function edgeValues(): array
    {
        return [self::INSIDE->value, self::OUTSIDE->value, self::UNCERTAIN->value];
    }
}
