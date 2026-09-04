<?php

namespace App\Enums\Vending;

enum AssignmentType: string
{
    case PRIMARY = 'PRIMARY';
    case TEMPORARY = 'TEMPORARY';
    case SUBSTITUTE = 'SUBSTITUTE';
    case SUPERVISOR = 'SUPERVISOR';
    case TECHNICIAN = 'TECHNICIAN';
    case ROUTE = 'ROUTE';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
