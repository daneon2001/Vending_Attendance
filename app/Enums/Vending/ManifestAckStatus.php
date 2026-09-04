<?php

namespace App\Enums\Vending;

enum ManifestAckStatus: string
{
    case APPLIED = 'APPLIED';
    case FAILED = 'FAILED';
}
