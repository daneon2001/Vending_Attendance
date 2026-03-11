<?php

namespace App\Services\FortiaMock;

use App\Models\Employee;
use App\Models\EmployeeStatusChange;
use App\Models\EmployeeSyncState;
use App\Models\FortiaMockEmployee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class FortiaMockSyncService
{
    public function syncIncremental(array $filters = []): array
    {
        $state = EmployeeSyncState::firstOrCreate(['source' => 'fortia_mock']);
        $remoteConnection = FortiaMockEmployee::on('fortia_mock')->getModel()->getConnectionName();
        $localConnection = (new Employee())->getConnectionName();
        $stateConnection = (new EmployeeSyncState())->getConnectionName();

        Log::info('Starting Fortia mock employee sync', [
            'source' => 'fortia_mock',
            'remote_connection' => $remoteConnection,
            'local_connection' => $localConnection,
            'state_connection' => $stateConnection,
        ]);

        try {
            $query = FortiaMockEmployee::on('fortia_mock')->newQuery();

            if ($state->last_synced_at) {
                $query->where('updated_at', '>=', $state->last_synced_at);
            }

            if (! empty($filters['company_id'])) {
                $query->where('company_id', $filters['company_id']);
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

            foreach ($remoteEmployees as $remote) {
                $summary['total']++;
                $maxUpdatedAt = $this->maxTimestamp($maxUpdatedAt, $remote->updated_at);
                $payload = $this->mapMockToEmployee($remote->toArray());

                $employee = Employee::query()
                    ->where('fortia_employee_id', $remote->employee_id)
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

                    EmployeeStatusChange::create([
                        'employee_id' => $employee->id,
                        'company_id' => $employee->company_id,
                        'fortia_employee_id' => $employee->fortia_employee_id,
                        'old_status' => $oldStatus,
                        'new_status' => $payload['status'],
                        'changed_at' => now(),
                        'source' => 'fortia_mock',
                        'meta' => [
                            'remote_updated_at' => $remote->updated_at?->toDateTimeString(),
                        ],
                    ]);

                    Log::info('Employee status changed from Fortia mock', $change);
                }

                if ($isDirty) {
                    $employee->save();
                    $summary['updated']++;
                } else {
                    $summary['unchanged']++;
                }
            }

            $this->updateSyncStateSuccess($state, $maxUpdatedAt, $summary);

            Log::info('Fortia mock employee sync completed', [
                'source' => 'fortia_mock',
                'summary' => $summary,
                'last_cursor' => $maxUpdatedAt?->toDateTimeString(),
            ]);

            return $summary;
        } catch (Throwable $e) {
            $this->updateSyncStateFailed($state, $e);
            throw $e;
        }
    }

    protected function mapMockToEmployee(array $remote): array
    {
        $fullName = trim(collect([$remote['name'] ?? '', $remote['last_name'] ?? '', $remote['second_last_name'] ?? ''])->filter()->implode(' '));

        return [
            'fortia_employee_id' => $remote['employee_id'],
            'company_id' => $remote['company_id'],
            'company_name' => $remote['company_name'],
            'base_location_id' => $remote['base_location_id'],
            'base_location_name' => $remote['base_location_name'],
            'department_id' => $remote['department_id'],
            'department_name' => $remote['department_name'],
            'name' => $remote['name'],
            'last_name' => $remote['last_name'],
            'second_last_name' => $remote['second_last_name'],
            'full_name' => $fullName,
            'status' => $remote['status'],
            'rfc' => $remote['rfc'],
            'imss_number' => $remote['imss_number'],
            'curp' => $remote['curp'],
            'email_company' => $remote['email_company'] ?? null,
        ];
    }

    protected function updateSyncStateSuccess(EmployeeSyncState $state, ?Carbon $maxUpdatedAt, array $summary): void
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

    protected function updateSyncStateFailed(EmployeeSyncState $state, Throwable $e): void
    {
        $state->fill([
            'last_sync_status' => 'failed',
            'last_error' => $e->getMessage(),
            'last_counts' => null,
        ])->save();

        Log::error('Fortia mock employee sync failed', [
            'source' => 'fortia_mock',
            'error' => $e->getMessage(),
        ]);
    }

    protected function maxTimestamp(?Carbon $current, ?Carbon $candidate): ?Carbon
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
