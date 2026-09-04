<?php

namespace App\Enums\Vending;

enum SybiVendingValidationStatus: string
{
    case READY = 'READY';
    case INCOMPLETE_LOCATION = 'INCOMPLETE_LOCATION';
    case IDENTIFIER_CONFLICT = 'IDENTIFIER_CONFLICT';
    case INVALID = 'INVALID';
    case SOURCE_MISSING = 'SOURCE_MISSING';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
