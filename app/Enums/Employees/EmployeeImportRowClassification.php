<?php

namespace App\Enums\Employees;

enum EmployeeImportRowClassification: string
{
    case VALID_NEW = 'VALID_NEW';
    case VALID_UPDATE = 'VALID_UPDATE';
    case UNCHANGED = 'UNCHANGED';
    case INVALID = 'INVALID';
    case DUPLICATE_FILE = 'DUPLICATE_FILE';
    case CONFLICT_SOURCE = 'CONFLICT_SOURCE';

    public function isApplicable(): bool
    {
        return in_array($this, [self::VALID_NEW, self::VALID_UPDATE], true);
    }
}
