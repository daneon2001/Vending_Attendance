<?php

namespace App\Enums\Vending;

enum VendingAttendanceEventType: string
{
    case CHECK_IN = 'CHECK_IN';
    case CHECK_OUT = 'CHECK_OUT';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
