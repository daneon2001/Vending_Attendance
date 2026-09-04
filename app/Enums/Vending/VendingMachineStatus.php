<?php

namespace App\Enums\Vending;

enum VendingMachineStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case MAINTENANCE = 'MAINTENANCE';
    case RETIRED = 'RETIRED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
