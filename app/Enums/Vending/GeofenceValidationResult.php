<?php

namespace App\Enums\Vending;

enum GeofenceValidationResult: string
{
    case INSIDE = 'INSIDE';
    case OUTSIDE = 'OUTSIDE';
    case UNCERTAIN = 'UNCERTAIN';
}
