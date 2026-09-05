<?php

namespace App\Enums\Vending;

enum MobileReleaseTargetType: string
{
    case CHANNEL = 'CHANNEL';
    case DEVICE = 'DEVICE';
    case GROUP = 'GROUP';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
