<?php

namespace App\Services\Fortia;

use App\Services\Employees\FortiaEmployeeSyncService;

/**
 * Compatibility facade: preserve legacy callers, use the minimal ownership-safe projection.
 * Source writes still require the explicit server-side write gate.
 */
class FortiaEmployeeService
{
    public function syncEmployees(array $filters = []): array
    {
        $result = app(FortiaEmployeeSyncService::class)->sync(false, $filters);

        return [...$result, 'total' => $result['received'], 'new' => $result['created'],
            'status_changed' => $result['status_changed'], 'changed' => [], 'scope_synced' => 0];
    }

    public function syncEmployeeById(int $fortiaEmployeeId): array
    {
        return $this->syncEmployees(['fortia_employee_id' => $fortiaEmployeeId]);
    }

    public function describeMode(): array
    {
        $isMock = $this->usingMockMode();

        return [
            'mode' => $isMock ? 'mock' : 'fortia',
            'label' => $isMock ? 'Fortia Mock' : 'Fortia',
            'is_mock' => $isMock,
            'connection' => $isMock ? 'fortia_mock' : config('fortia.sync_connection'),
            'table' => $isMock ? 'fortia_employees' : config('fortia.sync_table'),
        ];
    }

    public function usingMockMode(): bool
    {
        return strtolower(trim((string) config('fortia.sync_driver', 'fortia'))) === 'mock';
    }
}
