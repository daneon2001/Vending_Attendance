<?php

namespace App\Enums\Vending;

enum AttendanceReceiptStatus: string
{
    case STORED = 'STORED';
    case DUPLICATE = 'DUPLICATE';
    case REJECTED = 'REJECTED';
}
