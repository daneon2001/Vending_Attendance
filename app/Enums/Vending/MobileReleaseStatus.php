<?php

namespace App\Enums\Vending;

enum MobileReleaseStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case RETIRED = 'RETIRED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
