<?php

namespace App\Services\Employees;

use App\Enums\Employees\EmployeeImportRowClassification as Classification;
use App\Enums\Employees\EmployeeImportRunStatus as RunStatus;
use App\Enums\Employees\EmployeeSource;
use App\Models\Employee;
use App\Models\EmployeeImportRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmployeeImportService
{
    public function __construct(
        private readonly EmployeeImportFileReader $reader,
        private readonly EmployeeIdentityNormalizer $normalizer,
    ) {}

    public function stage(UploadedFile $file, User $actor): EmployeeImportRun
    {
        AuditLogger::log('employee.import.started', null, 'Employee file validation started.', ['initiated_by' => $actor->id]);
        try {
            $parsed = $this->reader->read($file);
            $duplicates = array_count_values(array_map(mb_strtolower(...), array_filter(array_column($parsed['rows'], 'employee_number'), fn ($value) => $value !== null)));

            return DB::transaction(function () use ($file, $actor, $parsed, $duplicates): EmployeeImportRun {
                $run = EmployeeImportRun::create([
                    'initiated_by' => $actor->id,
                    'file_name' => mb_substr(preg_replace('/[^\pL\pN._ -]/u', '_', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?? 'employees', 0, 191),
                    'file_hash' => hash_file('sha256', $file->getPathname()),
                    'file_extension' => strtolower($file->getClientOriginalExtension()),
                    'detected_mapping' => $parsed['mapping'],
                    'started_at' => now(),
                    'expires_at' => now()->addHours(max(1, (int) config('employees.import.staging_ttl_hours', 24))),
                ]);
                foreach ($parsed['rows'] as $candidate) {
                    $errors = $this->normalizer->errors($candidate, $candidate['row_number']);
                    if ($candidate['formula']) {
                        $errors[] = $this->rowError($candidate['row_number'], 'FORMULA_NOT_ALLOWED', 'La fila contiene una fórmula; usa valores explícitos.');
                    }
                    $classified = $this->classify($candidate);
                    if ($errors !== []) {
                        $classified = ['classification' => Classification::INVALID->value, 'employee_id' => null, 'changes' => []];
                    }
                    if (($duplicates[mb_strtolower($candidate['employee_number'] ?? '')] ?? 0) > 1) {
                        $classified['classification'] = Classification::DUPLICATE_FILE->value;
                        $errors[] = $this->rowError($candidate['row_number'], 'DUPLICATE_FILE', 'Todas las apariciones de este número quedan excluidas.');
                    }
                    if ($classified['classification'] === Classification::CONFLICT_SOURCE->value) {
                        $errors[] = $this->rowError($candidate['row_number'], 'CONFLICT_SOURCE', 'La identidad pertenece a otra fuente; este import no puede modificarla.');
                    }
                    $run->rows()->create([
                        ...$classified,
                        'row_number' => $candidate['row_number'],
                        'employee_number' => $candidate['employee_number'] === null ? null : mb_substr($candidate['employee_number'], 0, 120),
                        'full_name' => $candidate['full_name'] === null ? null : mb_substr($candidate['full_name'], 0, 255),
                        'normalized_status' => $candidate['status'],
                        'errors' => $errors,
                    ]);
                }
                $this->recount($run);

                return $run->fresh();
            });
        } catch (Throwable $exception) {
            AuditLogger::log('employee.import.failed', null, 'Employee file rejected.', ['failure_code' => 'FILE_VALIDATION_FAILED', 'initiated_by' => $actor->id]);
            throw $exception;
        }
        // UploadedFile remains in PHP's private request temp area, never copied to storage/public.
    }

    public function preview(EmployeeImportRun $run, int $page = 1): array
    {
        return [
            'uuid' => $run->uuid,
            'status' => $run->status->value,
            'file_name' => $run->file_name,
            'file_hash' => $run->file_hash,
            'mapping' => $run->detected_mapping,
            'expires_at' => $run->expires_at->toIso8601String(),
            'preview_hash' => $this->previewHash($run),
            'summary' => $run->only(['total_rows', 'valid_new', 'valid_update', 'unchanged', 'invalid', 'duplicates', 'conflicts']),
            'rows' => $run->rows()->orderBy('row_number')->paginate(
                min(100, max(1, (int) config('employees.import.preview_per_page', 50))),
                ['row_number', 'employee_number', 'full_name', 'normalized_status', 'classification', 'errors', 'changes'],
                'page', max(1, $page),
            )->toArray(),
        ];
    }

    public function apply(EmployeeImportRun $run, string $previewHash): array
    {
        try {
            return DB::transaction(function () use ($run, $previewHash): array {
                $run = EmployeeImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($run->status === RunStatus::COMPLETED) {
                    return ['stale' => false, 'run' => $run];
                }
                abort_if($run->expires_at->isPast(), 410, 'El preview expiró. Carga nuevamente el archivo.');
                abort_unless($run->status === RunStatus::PREVIEW, 409);
                abort_unless(hash_equals($this->previewHash($run), $previewHash), 409, 'Confirma la versión actual del preview.');
                $rows = $run->rows()->orderBy('employee_number')->get();
                $stale = false;
                foreach ($rows as $row) {
                    if (in_array($row->classification, [Classification::INVALID, Classification::DUPLICATE_FILE], true)) {
                        continue;
                    }
                    $classification = $this->classify([
                        'employee_number' => $row->employee_number, 'full_name' => $row->full_name, 'status' => $row->normalized_status,
                    ], lock: true);
                    if ($classification['classification'] !== $row->classification->value
                        || $classification['employee_id'] !== $row->employee_id
                        || ! $this->sameChanges($classification['changes'], $row->changes)) {
                        $stale = true;
                        $errors = $classification['classification'] === Classification::CONFLICT_SOURCE->value
                            ? [$this->rowError($row->row_number, 'CONFLICT_SOURCE', 'La identidad pertenece a otra fuente; este import no puede modificarla.')]
                            : [];
                        $row->fill([...$classification, 'errors' => $errors])->save();
                    }
                }
                if ($stale) {
                    $this->recount($run);

                    return ['stale' => true, 'run' => $run->fresh()];
                }
                $run->update(['status' => RunStatus::APPLYING]);
                foreach ($rows as $row) {
                    if (! $row->classification->isApplicable()) {
                        continue;
                    }
                    $employee = $row->employee_id ? Employee::findOrFail($row->employee_id) : new Employee;
                    $created = ! $employee->exists;
                    $statusChanged = ! $created && $this->normalizer->status($employee->status) !== $row->normalized_status;
                    if ($created) {
                        $employee->fill(['employee_number' => $row->employee_number, 'source' => EmployeeSource::MANUAL]);
                    }
                    $employee->fill(['full_name' => $row->full_name, 'status' => $row->normalized_status])->save();
                    $row->update(['employee_id' => $employee->id]);
                    AuditLogger::log($created ? 'employee.created' : ($statusChanged ? 'employee.status_changed' : 'employee.updated'), $employee, 'Controlled manual employee import.', [
                        'run_uuid' => $run->uuid, 'source' => 'MANUAL', 'fields' => array_keys($row->changes),
                    ]);
                }
                $run->update(['status' => RunStatus::COMPLETED, 'finished_at' => now()]);
                AuditLogger::log('employee.import.completed', $run, 'Employee import completed.', [
                    'run_uuid' => $run->uuid,
                    'counts' => $run->only(['total_rows', 'valid_new', 'valid_update', 'unchanged', 'invalid', 'duplicates', 'conflicts']),
                ]);

                return ['stale' => false, 'run' => $run->fresh()];
            }, 3);
        } catch (Throwable $exception) {
            AuditLogger::log('employee.import.failed', $run, 'Employee import was not applied.', ['failure_code' => 'APPLY_ABORTED']);
            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $exception;
            }
            EmployeeImportRun::whereKey($run->id)->where('status', RunStatus::PREVIEW->value)->update([
                'status' => RunStatus::FAILED->value, 'failure_code' => 'APPLY_ABORTED', 'finished_at' => now(),
            ]);
            // Never expose the database payload through a chained exception.
            throw new \RuntimeException('EMPLOYEE_IMPORT_APPLY_FAILED');
        }
    }

    private function classify(array $candidate, bool $lock = false): array
    {
        $employee = $candidate['employee_number'] === null ? null : Employee::query()
            ->where('employee_number', $candidate['employee_number'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
        $changes = [];
        foreach (['full_name', 'status'] as $field) {
            $before = $field === 'status' ? $this->normalizer->status($employee?->status) : $employee?->full_name;
            if ($before !== $candidate[$field]) {
                $changes[$field] = ['before' => $before, 'after' => $candidate[$field]];
            }
        }
        $classification = match (true) {
            $employee === null => Classification::VALID_NEW,
            $changes === [] => Classification::UNCHANGED,
            $employee->source !== EmployeeSource::MANUAL => Classification::CONFLICT_SOURCE,
            default => Classification::VALID_UPDATE,
        };

        return ['employee_id' => $employee?->id, 'classification' => $classification->value, 'changes' => $changes];
    }

    /**
     * JSON objects are unordered: MySQL may reorder both field and before/after keys.
     * Ignore key order only; retain strict value/type checks and every snapshot field.
     */
    private function sameChanges(array $current, array $snapshot): bool
    {
        if (count($current) !== count($snapshot)) {
            return false;
        }
        foreach ($current as $field => $change) {
            if (! isset($snapshot[$field]) || ! is_array($snapshot[$field])) {
                return false;
            }
            $stored = $snapshot[$field];
            ksort($change);
            ksort($stored);
            if ($change !== $stored) {
                return false;
            }
        }

        return true;
    }

    private function recount(EmployeeImportRun $run): void
    {
        $counts = $run->rows()->selectRaw('classification, count(*) AS total')->groupBy('classification')->pluck('total', 'classification');
        $run->update([
            'total_rows' => $counts->sum(),
            'valid_new' => $counts[Classification::VALID_NEW->value] ?? 0,
            'valid_update' => $counts[Classification::VALID_UPDATE->value] ?? 0,
            'unchanged' => $counts[Classification::UNCHANGED->value] ?? 0,
            'invalid' => $counts[Classification::INVALID->value] ?? 0,
            'duplicates' => $counts[Classification::DUPLICATE_FILE->value] ?? 0,
            'conflicts' => $counts[Classification::CONFLICT_SOURCE->value] ?? 0,
        ]);
    }

    private function previewHash(EmployeeImportRun $run): string
    {
        return hash('sha256', json_encode($run->rows()->orderBy('row_number')->get([
            'row_number', 'employee_id', 'employee_number', 'full_name', 'normalized_status', 'classification', 'changes',
        ])->toArray(), JSON_THROW_ON_ERROR));
    }

    private function rowError(int $row, string $code, string $reason): array
    {
        return ['row_number' => $row, 'field' => 'employee_number', 'code' => $code, 'reason' => $reason];
    }
}
