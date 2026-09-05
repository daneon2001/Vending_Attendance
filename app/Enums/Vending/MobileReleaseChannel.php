<?php

namespace App\Enums\Vending;

enum MobileReleaseChannel: string
{
    case DEV = 'DEV';
    case PILOT = 'PILOT';
    case PRODUCTION = 'PRODUCTION';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
