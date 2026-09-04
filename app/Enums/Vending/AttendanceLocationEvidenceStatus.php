<?php

namespace App\Enums\Vending;

enum AttendanceLocationEvidenceStatus: string
{
    case VALID = 'VALID';
    case MISSING = 'MISSING';
    case INVALID = 'INVALID';
    case LOW_ACCURACY = 'LOW_ACCURACY';
}
