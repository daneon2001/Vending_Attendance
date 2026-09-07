<?php

namespace App\Services\FortiaMock;

use App\Services\Fortia\FortiaEmployeeService;
use RuntimeException;

/** Retains the legacy entrypoint without payroll copies or automatic branch assignments. */
class FortiaMockSyncService
{
    public function syncIncremental(array $filters = []): array
    {
        $service = app(FortiaEmployeeService::class);
        if (! $service->usingMockMode()) {
            throw new RuntimeException('FORTIA_MOCK_DRIVER_REQUIRED');
        }

        return $service->syncEmployees($filters);
    }
}
