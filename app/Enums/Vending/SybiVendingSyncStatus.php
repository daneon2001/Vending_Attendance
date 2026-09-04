<?php

namespace App\Enums\Vending;

enum SybiVendingSyncStatus: string
{
    case NEVER_SYNCED = 'NEVER_SYNCED';
    case SYNCED = 'SYNCED';
    case SOURCE_MISSING = 'SOURCE_MISSING';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
