<?php

namespace App\Enums\Vending;

enum ManifestType: string
{
    case CONFIGURATION = 'CONFIGURATION';
    case EMPLOYEES = 'EMPLOYEES';
    case BIOMETRICS = 'BIOMETRICS';

    public function isSupported(): bool
    {
        return $this !== self::BIOMETRICS;
    }
}
