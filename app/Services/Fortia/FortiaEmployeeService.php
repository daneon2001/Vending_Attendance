<?php

namespace App\Services\Fortia;

use App\Models\Employee;
use App\Models\EmployeeStatusChange;
use App\Models\EmployeeSyncState;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class FortiaEmployeeService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function syncEmployees(array $filters = []): array
    {
        $connection = $this->connectionName();
        $table = $this->tableName();

        if (! Schema::connection($connection)->hasTable($table)) {
            throw new RuntimeException("La tabla {$connection}.{$table} no existe o no es accesible.");
        }

        $state = EmployeeSyncState::firstOrCreate(['source' => 'fortia']);
        $localConnection = (new Employee())->getConnectionName();
        $stateConnection = (new EmployeeSyncState())->getConnectionName();

        Log::info('Starting Fortia employee sync', [
            'source' => 'fortia',
            'remote_connection' => $connection,
            'remote_table' => $table,
            'local_connection' => $localConnection,
            'state_connection' => $stateConnection,
        ]);

        try {
            $query = DB::connection($connection)->table($table);

            if ($state->last_synced_at) {
                $query->where('updated_at', '>=', $state->last_synced_at);
            }

            if (! empty($filters['company_id'])) {
                $query->where('company_id', $filters['company_id']);
            }

            if (! empty($filters['fortia_employee_id'])) {
                $query->where(function ($employeeQuery) use ($filters): void {
                    $employeeQuery->where('employee_id', $filters['fortia_employee_id'])
                        ->orWhere('fortia_employee_id', $filters['fortia_employee_id']);
                });
            }

            $remoteEmployees = $query->orderBy('updated_at')->get();
            $summary = [
                'total' => 0,
                'new' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'status_changed' => 0,
                'changed' => [],
            ];

            $maxUpdatedAt = null;

            foreach ($remoteEmployees as $remoteRow) {
                $remote = (array) $remoteRow;
                $summary['total']++;
                $maxUpdatedAt = $this->maxTimestamp(
                    $maxUpdatedAt,
                    isset($remote['updated_at']) ? Carbon::parse((string) $remote['updated_at']) : null
                );

                $payload = $this->mapRemoteToEmployee($remote);
                if (! isset($payload['fortia_employee_id'])) {
                    continue;
                }

                $employee = Employee::query()
                    ->where('fortia_employee_id', $payload['fortia_employee_id'])
                    ->first();

                if (! $employee) {
                    Employee::create($payload);
                    $summary['new']++;
                    continue;
                }

                $oldStatus = $employee->status;
                $employee->fill($payload);
                $isDirty = $employee->isDirty();

                if ($oldStatus !== $payload['status']) {
                    $isDirty = true;
                    $summary['status_changed']++;
                    $change = [
                        'company_id' => $employee->company_id,
                        'fortia_employee_id' => $employee->fortia_employee_id,
                        'full_name' => $employee->full_name,
                        'old_status' => $oldStatus,
                        'new_status' => $payload['status'],
                        'changed_at' => now()->toDateTimeString(),
                    ];

                    $summary['changed'][] = $change;

                    if (Schema::hasTable('employee_status_changes')) {
                        EmployeeStatusChange::create([
                            'employee_id' => $employee->id,
                            'company_id' => $employee->company_id,
                            'fortia_employee_id' => $employee->fortia_employee_id,
                            'old_status' => $oldStatus,
                            'new_status' => $payload['status'],
                            'changed_at' => now(),
                            'source' => 'fortia',
                            'meta' => [
                                'remote_updated_at' => isset($remote['updated_at'])
                                    ? Carbon::parse((string) $remote['updated_at'])->toDateTimeString()
                                    : null,
                            ],
                        ]);
                    }
                }

                if ($isDirty) {
                    $employee->save();
                    $summary['updated']++;
                } else {
                    $summary['unchanged']++;
                }
            }

            $this->updateSyncStateSuccess($state, $maxUpdatedAt, $summary);

            Log::info('Fortia employee sync completed', [
                'source' => 'fortia',
                'summary' => $summary,
                'last_cursor' => $maxUpdatedAt?->toDateTimeString(),
            ]);

            return $summary;
        } catch (Throwable $exception) {
            $this->updateSyncStateFailed($state, $exception);
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function syncEmployeeById(int $fortiaEmployeeId): array
    {
        return $this->syncEmployees(['fortia_employee_id' => $fortiaEmployeeId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function describeMode(): array
    {
        $isMock = $this->usingMockMode();

        return [
            'mode' => $isMock ? 'mock' : 'fortia',
            'label' => $isMock ? 'Fortia Mock' : 'Fortia',
            'is_mock' => $isMock,
            'connection' => $isMock ? 'fortia_mock' : $this->connectionName(),
            'table' => $isMock ? 'fortia_employees' : $this->tableName(),
        ];
    }

    public function usingMockMode(): bool
    {
        return strtolower(trim((string) config('fortia.sync_driver', 'fortia'))) === 'mock';
    }

    private function connectionName(): string
    {
        return (string) config('fortia.sync_connection', 'fortia');
    }

    private function tableName(): string
    {
        return (string) config('fortia.sync_table', 'fortia_employees');
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function mapRemoteToEmployee(array $remote): array
    {
        $fortiaEmployeeId = $remote['employee_id'] ?? $remote['fortia_employee_id'] ?? null;
        if (! is_numeric($fortiaEmployeeId)) {
            return [];
        }

        $fullName = trim(collect([
            $remote['name'] ?? '',
            $remote['last_name'] ?? '',
            $remote['second_last_name'] ?? '',
        ])->filter()->implode(' '));

        $payload = [
            'fortia_employee_id' => (int) $fortiaEmployeeId,
            'company_id' => $remote['company_id'] ?? null,
            'company_name' => $remote['company_name'] ?? null,
            'base_location_id' => $remote['base_location_id'] ?? null,
            'base_location_name' => $remote['base_location_name'] ?? null,
            'department_id' => $remote['department_id'] ?? null,
            'department_name' => $remote['department_name'] ?? null,
            'name' => $remote['name'] ?? null,
            'last_name' => $remote['last_name'] ?? null,
            'second_last_name' => $remote['second_last_name'] ?? null,
            'full_name' => $fullName !== '' ? $fullName : null,
            'status' => $remote['status'] ?? 'B',
            'rfc' => $remote['rfc'] ?? null,
            'imss_number' => $remote['imss_number'] ?? null,
            'curp' => $remote['curp'] ?? null,
            'email_company' => $remote['email_company'] ?? null,
        ];

        if (Schema::hasColumn('employees', 'can_check_all_branches')) {
            $payload['can_check_all_branches'] = (bool) ($remote['can_check_all_branches'] ?? false);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function updateSyncStateSuccess(EmployeeSyncState $state, ?Carbon $maxUpdatedAt, array $summary): void
    {
        $state->fill([
            'last_cursor' => $maxUpdatedAt?->toDateTimeString(),
            'last_synced_at' => $maxUpdatedAt ?? $state->last_synced_at ?? now(),
            'last_success_at' => now(),
            'last_sync_status' => 'success',
            'last_error' => null,
            'last_counts' => [
                'total' => $summary['total'],
                'new' => $summary['new'],
                'updated' => $summary['updated'],
                'unchanged' => $summary['unchanged'],
                'status_changed' => $summary['status_changed'],
            ],
        ])->save();
    }

    private function updateSyncStateFailed(EmployeeSyncState $state, Throwable $exception): void
    {
        $state->fill([
            'last_sync_status' => 'failed',
            'last_error' => $exception->getMessage(),
            'last_counts' => null,
        ])->save();

        Log::error('Fortia employee sync failed', [
            'source' => 'fortia',
            'error' => $exception->getMessage(),
        ]);
    }

    private function maxTimestamp(?Carbon $current, ?Carbon $candidate): ?Carbon
    {
        if (! $candidate) {
            return $current;
        }

        if (! $current) {
            return $candidate;
        }

        return $candidate->greaterThan($current) ? $candidate : $current;
    }
}
