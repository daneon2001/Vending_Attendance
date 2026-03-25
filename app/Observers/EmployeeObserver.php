<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\Biometrics\EmployeeScopeSyncService;

class EmployeeObserver
{
    public function __construct(private readonly EmployeeScopeSyncService $scopeSyncService)
    {
    }

    public function updated(Employee $employee): void
    {
        $this->scopeSyncService->recordScopeLosses($employee);
    }
}
