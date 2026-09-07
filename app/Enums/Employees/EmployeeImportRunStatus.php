<?php

namespace App\Enums\Employees;

enum EmployeeImportRunStatus: string
{
    case PREVIEW = 'PREVIEW';
    case APPLYING = 'APPLYING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';
}
