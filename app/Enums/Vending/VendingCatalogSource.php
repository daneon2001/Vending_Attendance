<?php

namespace App\Enums\Vending;

enum VendingCatalogSource: string
{
    case LOCAL = 'LOCAL';
    case DEMO = 'DEMO';
    case SYBI = 'SYBI';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
