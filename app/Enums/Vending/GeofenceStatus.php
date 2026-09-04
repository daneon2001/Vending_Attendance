<?php

namespace App\Enums\Vending;

enum GeofenceStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case SUPERSEDED = 'SUPERSEDED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
