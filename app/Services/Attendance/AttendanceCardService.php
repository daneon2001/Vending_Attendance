<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDaily;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use App\Services\Employees\EmployeeCatalogQueryService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AttendanceCardService
{
    public const DEFAULT_PERIOD = 'today';

    public const EMPLOYEE_OPTIONS_LIMIT = 300;

    public function __construct(
        private readonly EmployeeCatalogQueryService $employeeCatalogQueryService
    ) {
    }

    private const PERIOD_OPTIONS = [
        'today' => 'Hoy',
        'current_week' => 'Semana actual',
        'previous_week' => 'Semana anterior',
        'current_fortnight' => 'Quincena actual',
        'previous_fortnight' => 'Quincena anterior',
        'current_month' => 'Mes actual',
        'previous_month' => 'Mes anterior',
        'custom' => 'Rango personalizado',
    ];

    public function buildPagePayload(array $filters, bool $requireEmployee = false): array
    {
        $normalized = $this->normalizeFilters($filters);
        $employeeQuery = $this->buildEmployeeQuery($normalized);
        $matchingEmployees = (clone $employeeQuery)->count();
        $selectedEmployee = $this->resolveSelectedEmployee($normalized, $normalized['employee_id']);

        $card = $selectedEmployee
            ? $this->buildEmployeeCard($selectedEmployee, $normalized)
            : $this->buildEmptyCard($normalized, $matchingEmployees);

        if ($requireEmployee && ! $selectedEmployee) {
            $card['requires_employee'] = true;
        }

        return [
            'filters' => $normalized['filters'],
            'period' => $normalized['period'],
            'timezone' => $this->timezoneMeta(),
            'periodOptions' => $this->periodOptions(),
            'companies' => $this->companyOptions(),
            'locations' => $this->locationOptions(),
            'departments' => $this->departmentOptions($normalized),
            'employees' => $this->employeeOptions($employeeQuery, $selectedEmployee),
            'employeeScope' => [
                'matching_count' => $matchingEmployees,
                'selected' => $selectedEmployee ? [
                    'id' => $selectedEmployee->id,
                    'name' => $this->employeeName($selectedEmployee),
                ] : null,
            ],
            'card' => $card,
            'flash' => [
                'status' => session('status'),
                'warning' => session('warning'),
            ],
        ];
    }

    public function buildExportPayload(array $filters): array
    {
        return $this->buildPagePayload($filters, true);
    }

    public function searchEmployeeOptions(array $filters, ?string $search = null, int $limit = 25): array
    {
        $normalized = $this->normalizeFilters($filters);
        $query = $this->buildEmployeeQuery($normalized, $search);

        return $this->mapEmployeeOptions(
            $query
                ->select($this->employeeOptionColumns())
                ->orderByRaw('COALESCE(full_name, name)')
                ->limit(max(1, min($limit, 50)))
                ->get()
        );
    }

    public function buildExportFilename(array $payload): string
    {
        $employeeName = (string) data_get($payload, 'card.employee.name', 'Empleado');
        $periodLabel = (string) data_get($payload, 'period.label', 'Periodo');
        $employeeSlug = Str::of($employeeName)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_');
        $periodSlug = Str::of($periodLabel)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_');

        return sprintf(
            'Tarjeta_Asistencia_%s_%s.xlsx',
            $employeeSlug !== '' ? $employeeSlug : 'Empleado',
            $periodSlug !== '' ? $periodSlug : now()->format('Ymd')
        );
    }

    public function exportLogoPath(): ?string
    {
        $path = public_path('images/medical-life-logo.png');

        return is_file($path) ? $path : null;
    }

    private function buildEmployeeCard(Employee $employee, array $normalized): array
    {
        $logs = AttendanceRecord::query()
            ->with([
                'location:id,name,code',
                'clock:id,clock_name,serial_number',
            ])
            ->where('employee_id', $employee->id)
            ->where('attendance_status', '!=', AttendanceRecord::STATUS_ANULADA)
            ->whereBetween('log_date', [
                $normalized['from_utc']->toDateTimeString(),
                $normalized['to_utc']->toDateTimeString(),
            ])
            ->orderBy('log_date')
            ->get();

        $dailySummaries = $this->loadDailySummaries($employee->id, $normalized);
        $preparedLogs = $this->prepareLogs($logs);
        $logsByDate = $preparedLogs->groupBy('work_date');
        $period = CarbonPeriod::create($normalized['from_local'], '1 day', $normalized['to_local']);
        $rows = collect();

        foreach ($period as $workDate) {
            $date = CarbonImmutable::instance($workDate)->startOfDay();
            $workDateKey = $date->toDateString();
            $dayLogs = $this->deduplicateLogs($logsByDate->get($workDateKey, collect()));
            $daySummary = $dailySummaries->get($workDateKey);

            $rows->push($this->buildRow(
                employee: $employee,
                workDate: $date,
                logs: $dayLogs,
                dailySummary: $daySummary,
            ));
        }

        $summary = $this->buildSummary($employee, $rows, $preparedLogs, $normalized);

        return [
            'requires_employee' => false,
            'employee' => [
                'id' => $employee->id,
                'name' => $this->employeeName($employee),
                'code' => (string) ($employee->fortia_employee_id ?? $employee->id),
                'company' => $employee->company_name
                    ?? $employee->company?->name
                    ?? 'Sin empresa',
                'location' => $employee->base_location_name
                    ?? $employee->baseLocation?->name
                    ?? 'Sin unidad',
                'department' => $employee->department_name ?: 'Sin departamento',
            ],
            'summary' => $summary,
            'rows' => $rows->all(),
        ];
    }

    private function buildEmptyCard(array $normalized, int $matchingEmployees): array
    {
        return [
            'requires_employee' => true,
            'employee' => null,
            'summary' => [
                'days_attended' => 0,
                'absences' => 0,
                'late_days' => 0,
                'worked_hours' => '0h 00m',
                'worked_minutes' => 0,
                'incomplete_days' => 0,
                'rest_days' => 0,
                'coverage_percentage' => 0,
                'coverage_label' => '0.0%',
                'last_attendance_at' => null,
                'last_attendance_display' => 'Sin registros',
                'matched_employees' => $matchingEmployees,
            ],
            'rows' => [],
            'empty_state' => [
                'title' => $matchingEmployees > 0
                    ? 'Selecciona un empleado'
                    : 'No hay empleados para estos filtros',
                'message' => $matchingEmployees > 0
                    ? 'Acota por empresa, unidad o departamento y elige un colaborador para generar su tarjeta.'
                    : 'No se encontraron empleados activos con los filtros seleccionados.',
            ],
        ];
    }

    private function buildSummary(
        Employee $employee,
        Collection $rows,
        Collection $preparedLogs,
        array $normalized,
    ): array {
        $daysAttended = $rows->where('has_marks', true)->count();
        $absences = $rows->where('status_key', 'absence')->count();
        $incompleteDays = $rows->where('status_key', 'incomplete')->count();
        $lateDays = $rows->filter(fn (array $row) => ($row['late_minutes'] ?? 0) > 0)->count();
        $restDays = $rows->where('status_key', 'rest')->count();
        $workingDays = max($rows->count() - $restDays, 0);
        $workedMinutes = (int) $rows->sum('worked_minutes');
        $lastAttendance = $preparedLogs->last()['local_at'] ?? null;
        $coverageBase = max($workingDays, 1);
        $coverage = $workingDays > 0
            ? round(($daysAttended / $coverageBase) * 100, 1)
            : 0.0;

        return [
            'days_attended' => $daysAttended,
            'absences' => $absences,
            'late_days' => $lateDays,
            'worked_hours' => $this->formatWorkedMinutes($workedMinutes),
            'worked_minutes' => $workedMinutes,
            'incomplete_days' => $incompleteDays,
            'rest_days' => $restDays,
            'coverage_percentage' => $coverage,
            'coverage_label' => number_format($coverage, 1).'%',
            'last_attendance_at' => $lastAttendance?->toIso8601String(),
            'last_attendance_display' => $lastAttendance
                ? $lastAttendance->locale('es_MX')->translatedFormat('d/m/Y h:i a')
                : 'Sin registros',
            'matched_employees' => null,
            'period_days' => $rows->count(),
            'working_days' => $workingDays,
            'employee_name' => $this->employeeName($employee),
            'period_label' => $normalized['period']['label'],
        ];
    }

    private function buildRow(
        Employee $employee,
        CarbonImmutable $workDate,
        Collection $logs,
        ?array $dailySummary,
    ): array {
        $entryTypes = $this->entryLogTypes();
        $exitTypes = $this->exitLogTypes();

        $firstEntry = $logs->first(fn (array $log) => in_array($log['log_type'], $entryTypes, true))
            ?? $logs->first();
        $lastExit = $logs->last(fn (array $log) => in_array($log['log_type'], $exitTypes, true));

        if (! $lastExit && $logs->count() > 1) {
            $lastExit = $logs->last();
        }

        if ($firstEntry && $lastExit && $firstEntry['id'] === $lastExit['id']) {
            $lastExit = null;
        }

        $statusKey = $this->resolveStatusKey($workDate, $logs, $firstEntry, $lastExit);
        $workedMinutes = $this->workedMinutes($firstEntry, $lastExit);
        $lateMinutes = max(
            (int) ($dailySummary['late_minutes'] ?? 0),
            0
        );
        $method = $this->resolvePrimaryMethod($logs);
        $sources = $logs->pluck('source_label')->filter()->unique()->values();
        $locations = $logs->pluck('location_name')->filter()->unique()->values();
        $clocks = $logs->pluck('clock_name')->filter()->unique()->values();
        $incidence = $this->resolveIncidenceLabel($statusKey, $lateMinutes);

        return [
            'date' => $workDate->toDateString(),
            'date_display' => $workDate->format('d/m/Y'),
            'day' => ucfirst($workDate->locale('es_MX')->isoFormat('dddd')),
            'entry_at' => $firstEntry ? $firstEntry['local_at']->toIso8601String() : null,
            'entry_display' => $firstEntry ? $firstEntry['local_at']->format('h:i a') : '--',
            'exit_at' => $lastExit ? $lastExit['local_at']->toIso8601String() : null,
            'exit_display' => $lastExit ? $lastExit['local_at']->format('h:i a') : '--',
            'worked_minutes' => $workedMinutes,
            'worked_hours_display' => $workedMinutes > 0 ? $this->formatWorkedMinutes($workedMinutes) : '--',
            'late_minutes' => $lateMinutes,
            'late_display' => $lateMinutes > 0 ? $lateMinutes.' min' : '--',
            'incidence_label' => $incidence,
            'method_label' => $method['label'],
            'source_label' => $sources->implode(' / ') ?: '--',
            'status_key' => $statusKey,
            'status_label' => $this->statusLabel($statusKey),
            'status_tone' => $this->statusTone($statusKey),
            'observations' => $this->buildObservation(
                employee: $employee,
                workDate: $workDate,
                logs: $logs,
                statusKey: $statusKey,
                locations: $locations,
                clocks: $clocks,
            ),
            'location_label' => $locations->implode(' / ') ?: ($employee->base_location_name ?: 'Sin unidad'),
            'clock_label' => $clocks->implode(' / ') ?: '--',
            'has_marks' => $logs->isNotEmpty(),
            'marks_count' => $logs->count(),
        ];
    }

    private function loadDailySummaries(int $employeeId, array $normalized): Collection
    {
        if (! Schema::hasTable('attendance_dailies')) {
            return collect();
        }

        return AttendanceDaily::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('work_date', [
                $normalized['from_local']->toDateString(),
                $normalized['to_local']->toDateString(),
            ])
            ->get()
            ->groupBy(fn (AttendanceDaily $daily) => $daily->work_date->toDateString())
            ->map(function (Collection $items): array {
                return [
                    'late_minutes' => (int) $items->max('late_minutes'),
                ];
            });
    }

    private function prepareLogs(Collection $logs): Collection
    {
        return $logs
            ->map(function (AttendanceRecord $record): array {
                $localAt = $this->resolveLocalLogDate($record);
                $method = $this->resolveLogMethod($record);
                $sourceLabel = $this->resolveLogSourceLabel($record);

                return [
                    'id' => (int) $record->id,
                    'log_type' => (int) $record->log_type,
                    'local_at' => $localAt,
                    'work_date' => $localAt->toDateString(),
                    'method_key' => $method['key'],
                    'method_label' => $method['label'],
                    'source_label' => $sourceLabel,
                    'location_name' => $record->location?->name,
                    'clock_name' => $record->clock?->clock_name
                        ?? ($record->clock?->serial_number ? 'Reloj '.$record->clock->serial_number : null),
                ];
            })
            ->sortBy(fn (array $item) => $item['local_at']->valueOf())
            ->values();
    }

    private function deduplicateLogs(Collection $logs): Collection
    {
        $windowSeconds = (int) config('attendance.consolidation.dedup_window_seconds', 90);

        if ($windowSeconds <= 0 || $logs->count() < 2) {
            return $logs->values();
        }

        $deduped = collect();

        foreach ($logs->sortBy(fn (array $item) => $item['local_at']->valueOf()) as $log) {
            /** @var array|null $previous */
            $previous = $deduped->last();

            if (! $previous) {
                $deduped->push($log);
                continue;
            }

            $sameType = (int) $previous['log_type'] === (int) $log['log_type'];
            $secondsGap = abs($log['local_at']->diffInSeconds($previous['local_at'], false));

            if ($sameType && $secondsGap <= $windowSeconds) {
                continue;
            }

            $deduped->push($log);
        }

        return $deduped->values();
    }

    private function buildEmployeeQuery(array $normalized, ?string $search = null): Builder
    {
        $query = Employee::query()
            ->with([
                'company:id,name,code',
                'baseLocation:id,name,code',
            ])
            ->where('status', 'A')
            ->when($normalized['company_id'], fn (Builder $query) => $query->where('company_id', $normalized['company_id']))
            ->when($normalized['department_id'], fn (Builder $query) => $query->where('department_id', $normalized['department_id']));

        $this->applyAttendanceLocationFilter($query, $normalized['location_id']);
        $this->employeeCatalogQueryService->applySearchFilter($query, $search);

        return $query;
    }

    private function resolveSelectedEmployee(array $normalized, ?int $employeeId): ?Employee
    {
        if (! $employeeId) {
            return null;
        }

        return $this->buildEmployeeQuery([
            ...$normalized,
            'employee_id' => null,
        ])->find($employeeId);
    }

    private function companyOptions(): array
    {
        if (! Schema::hasTable('companies')) {
            return [];
        }

        return Company::query()
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
            ])
            ->values()
            ->all();
    }

    private function locationOptions(): array
    {
        if (! Schema::hasTable('locations')) {
            return [];
        }

        return Location::query()
            ->select('id', 'name', 'code', 'company_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->code,
                'company_id' => $location->company_id,
            ])
            ->values()
            ->all();
    }

    private function departmentOptions(array $normalized): array
    {
        return $this->buildEmployeeQuery([
            ...$normalized,
            'employee_id' => null,
            'department_id' => null,
        ])
            ->select('department_id', 'department_name')
            ->whereNotNull('department_id')
            ->whereNotNull('department_name')
            ->distinct()
            ->orderBy('department_name')
            ->limit(self::EMPLOYEE_OPTIONS_LIMIT)
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->department_id,
                'name' => $employee->department_name,
            ])
            ->values()
            ->all();
    }

    private function employeeOptions(Builder $employeeQuery, ?Employee $selectedEmployee = null): array
    {
        $employees = (clone $employeeQuery)
            ->select($this->employeeOptionColumns())
            ->orderByRaw('COALESCE(full_name, name)')
            ->limit(min(self::EMPLOYEE_OPTIONS_LIMIT, 25))
            ->get();

        if ($selectedEmployee && ! $employees->contains(fn (Employee $employee) => (int) $employee->id === (int) $selectedEmployee->id)) {
            $employees->prepend($selectedEmployee);
        }

        return $this->mapEmployeeOptions($employees->unique('id')->values());
    }

    private function normalizeFilters(array $filters): array
    {
        $timezone = $this->operationsTimezone();
        $periodKey = $this->resolvePeriodKey($filters['period'] ?? null);
        $now = CarbonImmutable::now($timezone);
        [$fromLocal, $toLocal] = $this->resolvePeriodDates($periodKey, $filters, $now);
        $employeeId = ! empty($filters['employee_id']) ? (int) $filters['employee_id'] : null;
        $companyId = ! empty($filters['company_id']) ? (int) $filters['company_id'] : null;
        $locationId = ! empty($filters['location_id']) ? (int) $filters['location_id'] : null;
        $departmentId = ! empty($filters['department_id']) ? (int) $filters['department_id'] : null;

        return [
            'employee_id' => $employeeId,
            'company_id' => $companyId,
            'location_id' => $locationId,
            'department_id' => $departmentId,
            'from_local' => $fromLocal,
            'to_local' => $toLocal,
            'from_utc' => $fromLocal->setTimezone($this->storageTimezone()),
            'to_utc' => $toLocal->setTimezone($this->storageTimezone()),
            'filters' => [
                'employee_id' => $employeeId ? (string) $employeeId : '',
                'company_id' => $companyId ? (string) $companyId : '',
                'location_id' => $locationId ? (string) $locationId : '',
                'department_id' => $departmentId ? (string) $departmentId : '',
                'period' => $periodKey,
                'from_date' => $fromLocal->toDateString(),
                'to_date' => $toLocal->toDateString(),
            ],
            'period' => [
                'key' => $periodKey,
                'label' => self::PERIOD_OPTIONS[$periodKey] ?? self::PERIOD_OPTIONS[self::DEFAULT_PERIOD],
                'from_date' => $fromLocal->toDateString(),
                'to_date' => $toLocal->toDateString(),
                'display' => $fromLocal->format('d/m/Y').' - '.$toLocal->format('d/m/Y'),
            ],
        ];
    }

    private function resolvePeriodDates(
        string $periodKey,
        array $filters,
        CarbonImmutable $now,
    ): array {
        return match ($periodKey) {
            'current_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'previous_week' => [
                $now->subWeek()->startOfWeek(),
                $now->subWeek()->endOfWeek(),
            ],
            'current_fortnight' => $this->resolveFortnightRange($now),
            'previous_fortnight' => $this->resolvePreviousFortnightRange($now),
            'current_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'previous_month' => [
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow()->endOfMonth(),
            ],
            'custom' => $this->resolveCustomRange($filters, $now),
            default => [$now->startOfDay(), $now->endOfDay()],
        };
    }

    private function resolveFortnightRange(CarbonImmutable $reference): array
    {
        if ($reference->day <= 15) {
            return [$reference->startOfMonth(), $reference->startOfMonth()->addDays(14)->endOfDay()];
        }

        return [
            $reference->startOfMonth()->addDays(15)->startOfDay(),
            $reference->endOfMonth(),
        ];
    }

    private function resolvePreviousFortnightRange(CarbonImmutable $reference): array
    {
        if ($reference->day <= 15) {
            $previousMonth = $reference->subMonthNoOverflow();

            return [
                $previousMonth->startOfMonth()->addDays(15)->startOfDay(),
                $previousMonth->endOfMonth(),
            ];
        }

        return [
            $reference->startOfMonth(),
            $reference->startOfMonth()->addDays(14)->endOfDay(),
        ];
    }

    private function resolveCustomRange(array $filters, CarbonImmutable $fallback): array
    {
        $from = ! empty($filters['from_date'])
            ? CarbonImmutable::parse($filters['from_date'], $this->operationsTimezone())->startOfDay()
            : $fallback->startOfDay();
        $to = ! empty($filters['to_date'])
            ? CarbonImmutable::parse($filters['to_date'], $this->operationsTimezone())->endOfDay()
            : $from->endOfDay();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return [$from, $to];
    }

    private function resolvePeriodKey(?string $period): string
    {
        $candidate = is_string($period) ? trim($period) : '';

        return array_key_exists($candidate, self::PERIOD_OPTIONS)
            ? $candidate
            : self::DEFAULT_PERIOD;
    }

    private function resolveLocalLogDate(AttendanceRecord $record): CarbonImmutable
    {
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $rawUtc = $rawPayload['punched_at_utc']
            ?? $rawPayload['event_time_utc']
            ?? null;

        if (is_string($rawUtc) && trim($rawUtc) !== '') {
            return CarbonImmutable::parse($rawUtc, 'UTC')->setTimezone($this->operationsTimezone());
        }

        $rawLocal = $rawPayload['punched_at_local']
            ?? $rawPayload['event_time_local']
            ?? null;

        if (is_string($rawLocal) && trim($rawLocal) !== '') {
            $payloadTimezone = is_string($rawPayload['timezone'] ?? null) && trim((string) $rawPayload['timezone']) !== ''
                ? (string) $rawPayload['timezone']
                : $this->operationsTimezone();

            return CarbonImmutable::parse($rawLocal, $payloadTimezone)->setTimezone($this->operationsTimezone());
        }

        $rawLogDate = $record->getRawOriginal('log_date');

        if (is_string($rawLogDate) && trim($rawLogDate) !== '') {
            return CarbonImmutable::parse($rawLogDate, $this->storageTimezone())->setTimezone($this->operationsTimezone());
        }

        return CarbonImmutable::instance($record->log_date)->setTimezone($this->operationsTimezone());
    }

    private function resolveLogMethod(AttendanceRecord $record): array
    {
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $candidates = array_filter([
            $rawPayload['biometric_type'] ?? null,
            $rawPayload['method'] ?? null,
            $rawPayload['source'] ?? null,
            data_get($rawPayload, 'meta.method'),
            data_get($rawPayload, 'meta.biometric_type'),
            data_get($rawPayload, 'meta.source'),
        ], fn ($value) => is_string($value) && trim($value) !== '');

        foreach ($candidates as $candidate) {
            $normalized = Str::lower(trim((string) $candidate));

            if (in_array($normalized, ['fingerprint', 'finger', 'huella', 'scanner', 'digitalpersona'], true)) {
                return ['key' => 'fingerprint', 'label' => 'Huella'];
            }

            if (in_array($normalized, ['face', 'rostro', 'facial', 'camera', 'recognition'], true)) {
                return ['key' => 'face', 'label' => 'Rostro'];
            }

            if (in_array($normalized, ['manual'], true)) {
                return ['key' => 'manual', 'label' => 'Manual'];
            }
        }

        return match ((string) $record->source) {
            AttendanceRecord::SOURCE_MANUAL => ['key' => 'manual', 'label' => 'Manual'],
            AttendanceRecord::SOURCE_API => ['key' => 'api', 'label' => 'API'],
            AttendanceRecord::SOURCE_IMPORT => ['key' => 'import', 'label' => 'Import'],
            default => ['key' => 'sync', 'label' => 'No especificado'],
        };
    }

    private function resolveLogSourceLabel(AttendanceRecord $record): string
    {
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $provider = $rawPayload['provider'] ?? null;

        if (is_string($provider) && trim($provider) !== '') {
            return Str::headline($provider);
        }

        return match ((string) $record->source) {
            AttendanceRecord::SOURCE_API => 'API',
            AttendanceRecord::SOURCE_MANUAL => 'Manual',
            AttendanceRecord::SOURCE_IMPORT => 'Import',
            default => 'Sync',
        };
    }

    private function resolvePrimaryMethod(Collection $logs): array
    {
        $methods = $logs->pluck('method_key')->filter()->unique()->values();

        if ($methods->count() > 1) {
            return ['key' => 'mixed', 'label' => 'Mixto'];
        }

        $first = $logs->first();

        return [
            'key' => $first['method_key'] ?? 'unknown',
            'label' => $first['method_label'] ?? 'No especificado',
        ];
    }

    private function resolveStatusKey(
        CarbonImmutable $workDate,
        Collection $logs,
        ?array $firstEntry,
        ?array $lastExit,
    ): string {
        if ($logs->isEmpty()) {
            return $workDate->isWeekend() ? 'rest' : 'absence';
        }

        if (! $firstEntry || ! $lastExit) {
            return 'incomplete';
        }

        return 'attendance';
    }

    private function resolveIncidenceLabel(string $statusKey, int $lateMinutes): string
    {
        if ($lateMinutes > 0) {
            return 'Retardo';
        }

        return match ($statusKey) {
            'absence' => 'Falta',
            'incomplete' => 'Asistencia incompleta',
            'rest' => 'Descanso',
            default => '--',
        };
    }

    private function buildObservation(
        Employee $employee,
        CarbonImmutable $workDate,
        Collection $logs,
        string $statusKey,
        Collection $locations,
        Collection $clocks,
    ): string {
        if ($statusKey === 'absence') {
            return 'Sin registros de asistencia para este dia.';
        }

        if ($statusKey === 'rest') {
            return 'Dia sin registros operativos.';
        }

        if ($statusKey === 'incomplete') {
            return 'Solo se detecto una marca para este dia.';
        }

        $pieces = [];

        if ($locations->isNotEmpty()) {
            $pieces[] = 'Unidad: '.$locations->implode(' / ');
        } elseif ($employee->base_location_name) {
            $pieces[] = 'Unidad: '.$employee->base_location_name;
        }

        if ($clocks->isNotEmpty()) {
            $pieces[] = 'Reloj: '.$clocks->implode(' / ');
        }

        if ($logs->count() > 2) {
            $pieces[] = 'Se detectaron '.$logs->count().' marcas operativas.';
        }

        return $pieces !== [] ? implode(' | ', $pieces) : '--';
    }

    private function workedMinutes(?array $firstEntry, ?array $lastExit): int
    {
        if (! $firstEntry || ! $lastExit) {
            return 0;
        }

        $minutes = $lastExit['local_at']->diffInMinutes($firstEntry['local_at'], false);

        return $minutes > 0 ? $minutes : 0;
    }

    private function formatWorkedMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return sprintf('%dh %02dm', $hours, $remaining);
    }

    private function statusLabel(string $statusKey): string
    {
        return match ($statusKey) {
            'attendance' => 'Asistencia',
            'incomplete' => 'Incompleta',
            'rest' => 'Descanso',
            default => 'Falta',
        };
    }

    private function statusTone(string $statusKey): string
    {
        return match ($statusKey) {
            'attendance' => 'emerald',
            'incomplete' => 'amber',
            'rest' => 'slate',
            default => 'rose',
        };
    }

    private function employeeName(Employee $employee): string
    {
        return $employee->full_name ?: $employee->name ?: ('Empleado #'.$employee->id);
    }

    private function mapEmployeeOptions(Collection $employees): array
    {
        return $employees
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'value' => (string) $employee->id,
                'label' => sprintf(
                    '%s (%s)%s',
                    $this->employeeName($employee),
                    $employee->visibleEmployeeKey() ?? (string) ($employee->fortia_employee_id ?? $employee->id),
                    $employee->base_location_name ? ' · '.$employee->base_location_name : ''
                ),
                'name' => $this->employeeName($employee),
                'code' => $employee->visibleEmployeeKey() ?? (string) ($employee->fortia_employee_id ?? $employee->id),
                'fortia_employee_id' => $employee->fortia_employee_id !== null ? (string) $employee->fortia_employee_id : null,
                'location_name' => $employee->base_location_name,
                'department' => $employee->department_name,
                'company' => $employee->company_name,
                'searchText' => implode(' ', array_filter([
                    $this->employeeName($employee),
                    $employee->name,
                    $employee->last_name,
                    $employee->second_last_name,
                    $employee->visibleEmployeeKey(),
                    $employee->fortia_employee_id,
                    $employee->employee_code ?? null,
                    $employee->rfc ?? null,
                    $employee->curp ?? null,
                    $employee->base_location_name,
                ])),
            ])
            ->values()
            ->all();
    }

    private function applyAttendanceLocationFilter(Builder $query, ?int $locationId): void
    {
        if (! $locationId) {
            return;
        }

        $location = $this->resolveLocation($locationId);

        if (! $location) {
            $query->whereRaw('1 = 0');

            return;
        }

        $baseLocationCandidates = $this->resolveEmployeeBaseLocationCandidates($location);
        $localLocationId = (int) $location->id;

        $query->where(function (Builder $locationScope) use ($baseLocationCandidates, $localLocationId): void {
            if ($baseLocationCandidates !== []) {
                $locationScope->whereIn('base_location_id', $baseLocationCandidates);
            }

            if (Schema::hasColumn('employees', 'can_check_all_branches')) {
                $locationScope->orWhere('can_check_all_branches', true);
            }

            if (Schema::hasTable('employee_allowed_locations')) {
                $locationScope->orWhereHas('allowedLocations', function (Builder $allowedLocations) use ($localLocationId): void {
                    $allowedLocations->where('locations.id', $localLocationId);
                });
            }
        });
    }

    private function resolveLocation(int $locationId): ?Location
    {
        $columns = ['id', 'name'];

        if (Schema::hasColumn('locations', 'fortia_location_id')) {
            $columns[] = 'fortia_location_id';
        }

        if (Schema::hasColumn('locations', 'code')) {
            $columns[] = 'code';
        }

        return Location::query()
            ->select($columns)
            ->where(function (Builder $locationQuery) use ($locationId): void {
                $locationQuery->whereKey($locationId);

                if (Schema::hasColumn('locations', 'fortia_location_id')) {
                    $locationQuery->orWhere('fortia_location_id', $locationId);
                }

                if (Schema::hasColumn('locations', 'code')) {
                    $locationQuery->orWhere('code', (string) $locationId);
                }
            })
            ->first();
    }

    private function resolveEmployeeBaseLocationCandidates(Location $location): array
    {
        return collect([
            $location->id,
            is_numeric($location->fortia_location_id ?? null) ? (int) $location->fortia_location_id : null,
            is_numeric($location->code ?? null) ? (int) $location->code : null,
        ])
            ->filter(static fn ($value): bool => is_int($value) && $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function employeeOptionColumns(): array
    {
        $columns = [
            'id',
            'fortia_employee_id',
            'full_name',
            'name',
            'last_name',
            'second_last_name',
            'company_name',
            'base_location_name',
            'department_name',
        ];

        foreach (['employee_code', 'rfc', 'curp'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function entryLogTypes(): array
    {
        return collect(config('attendance.consolidation.entry_log_types', [1]))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    private function exitLogTypes(): array
    {
        return collect(config('attendance.consolidation.exit_log_types', [2, 4]))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    private function periodOptions(): array
    {
        return collect(self::PERIOD_OPTIONS)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function timezoneMeta(): array
    {
        $now = CarbonImmutable::now($this->operationsTimezone());

        return [
            'name' => $this->operationsTimezone(),
            'label' => (string) config('operations.timezone_label', 'Hora centro de Mexico'),
            'offset' => $now->format('P'),
        ];
    }

    private function operationsTimezone(): string
    {
        return (string) config('operations.timezone', 'America/Mexico_City');
    }

    private function storageTimezone(): string
    {
        return (string) config('operations.storage_timezone', 'UTC');
    }
}
