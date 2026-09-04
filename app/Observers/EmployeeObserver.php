<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\Biometrics\EmployeeScopeSyncService;
use App\Services\Vending\EmployeeManifestVersionService;

class EmployeeObserver
{
    public function __construct(
        private readonly EmployeeScopeSyncService $scopeSyncService,
        private readonly EmployeeManifestVersionService $manifestVersions,
    ) {}

    public function updated(Employee $employee): void
    {
        $this->scopeSyncService->recordScopeLosses($employee);
        $this->manifestVersions->bumpForEmployee($employee);
    }
}
