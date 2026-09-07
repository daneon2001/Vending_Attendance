<?php

namespace App\Enums\Employees;

enum EmployeeSource: string
{
    case FORTIA = 'FORTIA';
    case MANUAL = 'MANUAL';
    case DEMO = 'DEMO';
    case LEGACY = 'LEGACY';

    public function isManualImportWritable(): bool
    {
        return $this === self::MANUAL;
    }
}
