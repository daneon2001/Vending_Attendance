<?php

namespace App\Services\Employees;

use App\Enums\Employees\EmployeeSource;
use App\Models\Employee;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\Fortia\FortiaEmployeeClientFactory;
use App\Services\Employees\Fortia\FortiaEmployeeMapper;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FortiaEmployeeSyncService
{
    public function __construct(
        private readonly FortiaEmployeeClientFactory $clients,
        private readonly FortiaEmployeeMapper $mapper,
        private readonly EmployeeIdentityNormalizer $normalizer,
    ) {}

    public function sync(bool $dryRun = true, array $filters = []): array
    {
        if (! $dryRun && config('employees.fortia.allow_write') !== true) {
            throw new RuntimeException('FORTIA_WRITE_DISABLED');
        }
        if (! $dryRun) {
            AuditLogger::log('employee.sync.started', null, 'Employee source synchronization started.');
        }
        try {
            $raw = $this->clients->make()->fetch($filters);
            $rows = array_map($this->mapper->normalize(...), $raw);
            $valid = array_filter($rows, fn ($row) => $row !== null);
            $numbers = array_count_values(array_map(fn ($row) => mb_strtolower($row['employee_number']), $valid));
            $ids = array_count_values(array_map(fn ($row) => mb_strtolower($row['source_external_id']), $valid));
            $source = strtolower(trim((string) config('fortia.sync_driver'))) === 'mock' ? EmployeeSource::DEMO : EmployeeSource::FORTIA;
            $process = function () use ($rows, $numbers, $ids, $source, $dryRun): array {
                $result = ['received' => count($rows), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'inactive' => 0, 'rejected' => 0, 'conflicts' => 0, 'status_changed' => 0];
                foreach ($rows as $row) {
                    if ($row === null) {
                        $result['rejected']++;

                        continue;
                    }
                    if ($numbers[mb_strtolower($row['employee_number'])] > 1 || $ids[mb_strtolower($row['source_external_id'])] > 1) {
                        $result['conflicts']++;

                        continue;
                    }
                    $employee = Employee::where('employee_number', $row['employee_number'])
                        ->when(! $dryRun, fn ($query) => $query->lockForUpdate())->first();
                    $external = Employee::where('source', $source->value)->where('source_external_id', $row['source_external_id'])
                        ->when(! $dryRun, fn ($query) => $query->lockForUpdate())->first();
                    if (($employee && ($employee->source !== $source || $employee->source_external_id !== $row['source_external_id']))
                        || ($external && $external->id !== $employee?->id)
                        || ($employee?->source_updated_at && $row['source_updated_at'] && $employee->source_updated_at->gt($row['source_updated_at']))) {
                        $result['conflicts']++;

                        continue;
                    }
                    $created = $employee === null;
                    $changed = ! $created && ($employee->full_name !== $row['full_name'] || $this->normalizer->status($employee->status) !== $row['status']);
                    $result[$created ? 'created' : ($changed ? 'updated' : 'unchanged')]++;
                    $result['inactive'] += $row['status'] === 'B' ? 1 : 0;
                    $statusChanged = ! $created && $this->normalizer->status($employee->status) !== $row['status'];
                    $result['status_changed'] += $statusChanged ? 1 : 0;
                    if ($dryRun) {
                        continue;
                    }
                    $employee ??= new Employee;
                    // Do not clear a known source cursor when an optional timestamp is absent.
                    $row['source_updated_at'] ??= $employee->source_updated_at;
                    if (! $created && ! $statusChanged) {
                        $row['status'] = $employee->status;
                    }
                    $employee->fill([...$row, 'source' => $source, 'source_synced_at' => now()])->save();
                    if ($created || $changed) {
                        AuditLogger::log($created ? 'employee.created' : ($statusChanged ? 'employee.status_changed' : 'employee.updated'), $employee, 'Employee source projection updated.', [
                            'source' => $source->value, 'fields' => ['full_name', 'status'],
                        ]);
                    }
                }

                return $result;
            };
            $result = $dryRun ? $process() : DB::transaction($process, 3);
            if (! $dryRun) {
                AuditLogger::log('employee.sync.completed', null, 'Employee source synchronization completed.', ['counts' => $result]);
            }

            return $result;
        } catch (Throwable $exception) {
            if (! $dryRun) {
                AuditLogger::log('employee.sync.failed', null, 'Employee source synchronization failed.', ['failure_code' => 'SYNC_FAILED']);
            }
            $code = $exception instanceof RuntimeException && preg_match('/^FORTIA_[A-Z0-9_]+$/', $exception->getMessage())
                ? $exception->getMessage() : 'FORTIA_SYNC_FAILED';
            throw new RuntimeException($code);
        }
    }
}
