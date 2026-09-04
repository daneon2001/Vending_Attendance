<?php

namespace App\Enums\Vending;

enum SybiVendingSourceStatus: string
{
    case PRESENT = 'PRESENT';
    case SOURCE_MISSING = 'SOURCE_MISSING';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
