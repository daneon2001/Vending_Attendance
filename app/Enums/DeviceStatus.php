<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case REVOKED = 'REVOKED';
    case RETIRED = 'RETIRED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isOperational(): bool
    {
        return $this === self::ACTIVE;
    }
}
