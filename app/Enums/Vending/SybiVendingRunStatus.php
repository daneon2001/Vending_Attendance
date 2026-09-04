<?php

namespace App\Enums\Vending;

enum SybiVendingRunStatus: string
{
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case COMPLETED_WITH_WARNINGS = 'COMPLETED_WITH_WARNINGS';
    case FAILED = 'FAILED';
}
