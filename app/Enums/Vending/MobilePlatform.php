<?php

namespace App\Enums\Vending;

enum MobilePlatform: string
{
    case ANDROID = 'ANDROID';
    case IOS = 'IOS';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
