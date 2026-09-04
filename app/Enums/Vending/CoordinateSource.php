<?php

namespace App\Enums\Vending;

enum CoordinateSource: string
{
    case SYBI = 'SYBI';
    case IMPORT = 'IMPORT';
    case MANUAL = 'MANUAL';
    case GPS_INSTALLATION = 'GPS_INSTALLATION';
    case GEOCODED = 'GEOCODED';
    case LEGACY = 'LEGACY';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
