<?php

namespace App\Enums\Vending;

enum AssignmentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
