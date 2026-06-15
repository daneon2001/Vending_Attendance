<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttendanceAdjustmentRequest;
use App\Http\Requests\Admin\AttendanceAnnulRequest;
use App\Http\Requests\Admin\AttendanceFilterRequest;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\Clock;
use App\Models\Employee;
use App\Models\Location;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(AttendanceFilterRequest $request): Response
    {
        $filters = $this->normalizeFilters($request->validated());
        $viewMode = $filters['view_mode'] ?? 'grouped';
        $records = $viewMode === 'grouped'
            ? $this->buildGroupedIndexPayload($filters, (int) $request->integer('page', 1))
            : $this->buildRawIndexPayload($filters);

        $employees = Employee::query()
            ->select('id', 'full_name', 'name', 'fortia_employee_id')
            ->orderBy('full_name')
            ->limit(300)
            ->get();

        $locations = Location::query()
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();

        $clocks = Clock::query()
            ->select('id', 'clock_name', 'serial_number', 'location_id')
            ->orderBy('clock_name')
            ->get();

        return Inertia::render('Attendance/Index', [
            'initialRecords' => $records,
            'filters' => $filters,
            'viewMode' => $viewMode,
            'columns' => $this->attendanceColumnConfig(),
            'employees' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->full_name ?? $employee->name ?? ('Empleado #'.$employee->id),
                'code' => (string) ($employee->fortia_employee_id ?? $employee->id),
            ])->values(),
            'locations' => $locations->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->code,
            ])->values(),
            'clocks' => $clocks->map(fn (Clock $clock) => [
                'id' => $clock->id,
                'name' => $clock->clock_name,
                'serial_number' => $clock->serial_number,
                'location_id' => $clock->location_id,
            ])->values(),
            'typeLabels' => $this->logTypeLabels(),
            'statusLabels' => $this->statusLabels(),
            'sourceLabels' => $this->sourceLabels(),
            'flash' => [
                'status' => session('status'),
                'warning' => session('warning'),
            ],
        ]);
    }

    public function groupedDetail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'employee' => ['nullable', 'string', 'max:150'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'device_id' => ['nullable', 'integer', 'exists:clocks,id'],
            'type' => ['nullable', 'in:in,out,unknown,0,1,2,3,4'],
            'source' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', 'max:30'],
            'local_date' => ['required', 'date'],
        ]);

        $filters = $this->normalizeFilters($validated);
        $localDate = Carbon::parse($validated['local_date'])->toDateString();

        $records = $this->buildFilteredQuery($filters)
            ->with($this->rawRecordRelations())
            ->orderBy('log_date')
            ->get()
            ->filter(function (AttendanceRecord $record) use ($localDate): bool {
                return $this->resolveRecordLocalDate($record)?->toDateString() === $localDate;
            })
            ->values();

        if ($records->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron checadas para el colaborador en la fecha solicitada.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformGroupedDetail($records, $filters),
        ]);
    }

    public function show(int|string $attendance_record): Response
    {
        $attendanceRecord = AttendanceRecord::query()->findOrFail((int) $attendance_record);

        $attendanceRecord->load([
            'employee:id,full_name,name,fortia_employee_id,base_location_name,company_name',
            'location:id,name,code,timezone',
            'clock:id,clock_name,serial_number,ip_address,location_id',
            'clock.location:id,name,code,timezone',
            'annulledBy:id,name,email',
            'audits.changedBy:id,name,email',
        ]);

        return Inertia::render('Attendance/Show', [
            'record' => $this->transformShowRecord($attendanceRecord),
            'typeLabels' => $this->logTypeLabels(),
            'statusLabels' => $this->statusLabels(),
            'sourceLabels' => $this->sourceLabels(),
            'flash' => [
                'status' => session('status'),
                'warning' => session('warning'),
            ],
        ]);
    }

    public function annul(AttendanceAnnulRequest $request, int|string $attendance_record): RedirectResponse
    {
        $attendanceRecord = AttendanceRecord::query()->findOrFail((int) $attendance_record);

        $before = $this->serializeRecord($attendanceRecord);
        $reason = trim((string) $request->input('reason'));
        $user = $request->user();

        if ($attendanceRecord->attendance_status === AttendanceRecord::STATUS_ANULADA) {
            return redirect()
                ->back()
                ->with('warning', 'El registro ya estaba anulado.');
        }

        $attendanceRecord->forceFill([
            'attendance_status' => AttendanceRecord::STATUS_ANULADA,
            'adjustment_reason' => $reason,
            'annulled_at' => now(),
            'annulled_by_user_id' => $user?->id,
        ])->save();

        $attendanceRecord->refresh();

        $this->storeAudit(
            request: $request,
            attendance: $attendanceRecord,
            action: 'annulled',
            reason: $reason,
            beforeData: $before,
            afterData: $this->serializeRecord($attendanceRecord),
        );

        return redirect()
            ->back()
            ->with('status', 'Registro anulado correctamente.');
    }

    public function storeManualAdjustment(AttendanceAdjustmentRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        $logType = $this->normalizeLogType($validated['log_type']);
        $user = $request->user();
        $record = null;

        DB::transaction(function () use ($validated, $employee, $logType, $user, $request, &$record): void {
            $nextLogId = ((int) (AttendanceRecord::query()->max('log_id') ?? 0)) + 1;

            $payload = [
                'log_id' => $nextLogId,
                'employee_id' => $employee->id,
                'fortia_employee_id' => $employee->fortia_employee_id,
                'company_id' => $employee->company_id,
                'location_id' => $validated['location_id'] ?? $employee->base_location_id,
                'device_id' => $validated['device_id'] ?? null,
                'log_date' => Carbon::parse($validated['log_date']),
                'log_type' => $logType,
                'source' => AttendanceRecord::SOURCE_MANUAL,
                'attendance_status' => AttendanceRecord::STATUS_CORREGIDA,
                'adjustment_reason' => trim((string) $validated['reason']),
                'raw_payload' => [
                    'manual_note' => $validated['notes'] ?? null,
                    'created_from' => 'admin.central_asistencias',
                ],
            ];

            if (Schema::hasColumn('attendance_logs', 'ingested_at_utc')) {
                $payload['ingested_at_utc'] = now('UTC');
            }
            if (Schema::hasColumn('attendance_logs', 'ingest_ip')) {
                $payload['ingest_ip'] = $request->ip();
            }
            if (Schema::hasColumn('attendance_logs', 'request_id')) {
                $payload['request_id'] = (string) ($request->attributes->get('request_id') ?? null);
            }
            if (Schema::hasColumn('attendance_logs', 'auth_key_id')) {
                $payload['auth_key_id'] = 'web_session:'.($user?->id ?? 'system');
            }
            if (Schema::hasColumn('attendance_logs', 'device_serial') && ! empty($validated['device_id'])) {
                $payload['device_serial'] = Clock::query()->whereKey((int) $validated['device_id'])->value('serial_number');
            }

            $record = AttendanceRecord::query()->create($payload);

            $this->storeAudit(
                request: $request,
                attendance: $record,
                action: 'manual_adjustment',
                reason: trim((string) $validated['reason']),
                beforeData: null,
                afterData: $this->serializeRecord($record),
                changedById: $user?->id,
                changedByName: $user?->name,
                changedByEmail: $user?->email,
            );
        });

        return redirect()
            ->route('admin.asistencias.show', $record)
            ->with('status', 'Ajuste manual registrado correctamente.');
    }

    public function export(AttendanceFilterRequest $request): StreamedResponse
    {
        $filters = $this->normalizeFilters($request->validated());
        $format = $filters['format'] ?? 'csv';
        $viewMode = $filters['view_mode'] ?? 'grouped';
        $filenameBase = 'central_asistencias_'.now()->format('Ymd_His');
        $columns = $this->normalizeRequestedColumns($filters['columns'] ?? [], $viewMode);
        $headers = collect($this->columnsForMode($viewMode))
            ->keyBy('key');

        AuditLogger::log(
            event: 'attendance.records.exported',
            auditable: null,
            description: 'Attendance export generated',
            metadata: [
                'action' => 'export',
                'entity' => 'attendance_logs',
                'reason' => 'manual_export',
                'new_values' => [
                    'format' => $format,
                    'view_mode' => $viewMode,
                    'columns' => $columns,
                    'filters' => $filters,
                ],
            ],
        );

        $streamCallback = function () use ($filters, $viewMode, $columns, $headers): void {
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, $columns
                ? collect($columns)->map(fn (string $key) => $headers[$key]['label'] ?? $key)->all()
                : collect($this->defaultColumnsForMode($viewMode))->map(fn (string $key) => $headers[$key]['label'] ?? $key)->all()
            );

            if ($viewMode === 'grouped') {
                foreach ($this->buildGroupedRows($filters) as $row) {
                    fputcsv($output, $this->exportValuesForGroupedRow($row, $columns));
                }
            } else {
                $records = $this->buildFilteredQuery($filters)
                    ->with($this->rawRecordRelations())
                    ->orderByDesc('log_date')
                    ->get();

                foreach ($records as $record) {
                    fputcsv($output, $this->exportValuesForRawRecord($this->transformIndexRecord($record), $columns));
                }
            }

            fclose($output);
        };

        if ($format === 'excel') {
            return response()->streamDownload(
                $streamCallback,
                $filenameBase.'.xls',
                [
                    'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                ]
            );
        }

        return response()->streamDownload(
            $streamCallback,
            $filenameBase.'.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]
        );
    }

    protected function buildFilteredQuery(array $filters): Builder
    {
        $query = AttendanceRecord::query()
            ->withinDateRange($filters['from'] ?? null, $filters['to'] ?? null)
            ->byStatus($filters['status'] ?? null)
            ->bySource($filters['source'] ?? null);

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['employee'])) {
            $search = trim((string) $filters['employee']);
            $query->whereHas('employee', function (Builder $employeeQuery) use ($search): void {
                $employeeQuery->where('full_name', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('fortia_employee_id', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        if (! empty($filters['device_id'])) {
            $query->where('device_id', (int) $filters['device_id']);
        }

        if (! empty($filters['type'])) {
            $this->applyTypeFilter($query, (string) $filters['type']);
        }

        return $query;
    }

    protected function buildRawIndexPayload(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 25);

        $records = $this->buildFilteredQuery($filters)
            ->with($this->rawRecordRelations())
            ->orderByDesc('log_date')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'data' => $records->getCollection()
                ->map(fn (AttendanceRecord $record) => $this->transformIndexRecord($record))
                ->values(),
            'meta' => $this->transformPaginatorMeta($records),
        ];
    }

    protected function buildGroupedIndexPayload(array $filters, int $currentPage = 1): array
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $rows = $this->buildGroupedRows($filters);
        $paginated = new LengthAwarePaginator(
            $rows->slice(max(0, ($currentPage - 1) * $perPage), $perPage)->values(),
            $rows->count(),
            $perPage,
            max(1, $currentPage),
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );

        return [
            'data' => $paginated->getCollection()->values()->all(),
            'meta' => $this->transformPaginatorMeta($paginated),
        ];
    }

    protected function buildGroupedRows(array $filters): Collection
    {
        $records = $this->buildFilteredQuery($filters)
            ->with($this->rawRecordRelations())
            ->orderBy('employee_id')
            ->orderBy('log_date')
            ->get();

        $groups = [];

        foreach ($records as $record) {
            $localDate = $this->resolveRecordLocalDate($record);
            $localDateKey = $localDate?->toDateString()
                ?? $record->log_date?->copy()->setTimezone($this->attendanceFallbackTimezone())->toDateString()
                ?? now($this->attendanceFallbackTimezone())->toDateString();
            $groupKey = sprintf('employee_%s_%s', $record->employee_id ?? 'na', $localDateKey);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'employee_id' => $record->employee?->id ?? $record->employee_id,
                    'fortia_employee_id' => (string) ($record->employee?->fortia_employee_id ?? $record->fortia_employee_id ?? ''),
                    'employee_code' => (string) ($record->employee?->fortia_employee_id ?? $record->fortia_employee_id ?? $record->employee_id ?? ''),
                    'employee_name' => $record->employee?->full_name ?? $record->employee?->name ?? 'N/A',
                    'department' => $this->resolveEmployeeDepartment($record),
                    'position' => $this->resolveEmployeePosition($record),
                    'company' => $this->resolveEmployeeCompany($record),
                    'local_date' => $localDateKey,
                    'local_date_display' => $localDate?->format('Y-m-d') ?? $localDateKey,
                    'first_check_at' => $localDate?->toIso8601String(),
                    'first_check_display' => $localDate?->format('Y-m-d H:i:s'),
                    'last_check_at' => $localDate?->toIso8601String(),
                    'last_check_display' => $localDate?->format('Y-m-d H:i:s'),
                    'total_checks' => 0,
                    'entry_count' => 0,
                    'exit_count' => 0,
                    'location_name' => null,
                    'clock_name' => null,
                    'first_location_name' => $this->resolveRecordLocationName($record),
                    'last_location_name' => $this->resolveRecordLocationName($record),
                    'first_clock_name' => $this->resolveRecordClockName($record),
                    'last_clock_name' => $this->resolveRecordClockName($record),
                    'has_multiple_locations' => false,
                    'has_multiple_clocks' => false,
                    'source_label' => $this->sourceLabels()[$record->source] ?? strtoupper((string) $record->source),
                    'type_label' => $this->resolveLogTypeLabel((int) $record->log_type),
                    'status' => $record->attendance_status,
                    'status_label' => $this->statusLabels()[$record->attendance_status] ?? ucfirst((string) $record->attendance_status),
                    'status_summary' => [$record->attendance_status => 1],
                    'sources' => [$record->source => 1],
                    'types' => [(string) $record->log_type => 1],
                    'location_names' => array_values(array_filter([$this->resolveRecordLocationName($record)])),
                    'clock_names' => array_values(array_filter([$this->resolveRecordClockName($record)])),
                    'first_record_id' => $record->id,
                    'last_record_id' => $record->id,
                    'created_at' => $record->created_at?->toIso8601String(),
                    'created_at_display' => $record->created_at?->setTimezone($this->attendanceFallbackTimezone())->format('Y-m-d H:i:s'),
                    'updated_at' => $record->updated_at?->toIso8601String(),
                    'updated_at_display' => $record->updated_at?->setTimezone($this->attendanceFallbackTimezone())->format('Y-m-d H:i:s'),
                    'observation_summary' => $record->adjustment_reason,
                ];
            }

            $row = &$groups[$groupKey];
            $recordLocalDate = $localDate ?? $this->resolveRecordLocalDate($record);
            $locationName = $this->resolveRecordLocationName($record);
            $clockName = $this->resolveRecordClockName($record);

            $row['total_checks']++;
            $row['first_record_id'] = $row['first_record_id'] ?? $record->id;
            $row['last_record_id'] = $record->id;

            if ((int) $record->log_type === 1) {
                $row['entry_count']++;
            }

            if (in_array((int) $record->log_type, [2, 4], true)) {
                $row['exit_count']++;
            }

            if ($recordLocalDate && ($row['first_check_at'] === null || $recordLocalDate->lt(Carbon::parse($row['first_check_at'])))) {
                $row['first_check_at'] = $recordLocalDate->toIso8601String();
                $row['first_check_display'] = $recordLocalDate->format('Y-m-d H:i:s');
                $row['first_location_name'] = $locationName;
                $row['first_clock_name'] = $clockName;
                $row['first_record_id'] = $record->id;
            }

            if ($recordLocalDate && ($row['last_check_at'] === null || $recordLocalDate->gt(Carbon::parse($row['last_check_at'])))) {
                $row['last_check_at'] = $recordLocalDate->toIso8601String();
                $row['last_check_display'] = $recordLocalDate->format('Y-m-d H:i:s');
                $row['last_location_name'] = $locationName;
                $row['last_clock_name'] = $clockName;
                $row['last_record_id'] = $record->id;
            }

            if ($locationName) {
                $row['location_names'][] = $locationName;
            }

            if ($clockName) {
                $row['clock_names'][] = $clockName;
            }

            $row['status_summary'][$record->attendance_status] = ($row['status_summary'][$record->attendance_status] ?? 0) + 1;
            $row['sources'][$record->source] = ($row['sources'][$record->source] ?? 0) + 1;
            $row['types'][(string) $record->log_type] = ($row['types'][(string) $record->log_type] ?? 0) + 1;
            $row['observation_summary'] = $row['observation_summary'] ?: $record->adjustment_reason;
        }

        return collect($groups)
            ->map(function (array $row): array {
                $uniqueLocations = collect($row['location_names'])->filter()->unique()->values();
                $uniqueClocks = collect($row['clock_names'])->filter()->unique()->values();

                $row['has_multiple_locations'] = $uniqueLocations->count() > 1;
                $row['has_multiple_clocks'] = $uniqueClocks->count() > 1;
                $row['location_name'] = $row['has_multiple_locations']
                    ? 'Múltiples'
                    : ($uniqueLocations->first() ?? $row['first_location_name'] ?? 'Sin unidad');
                $row['clock_name'] = $row['has_multiple_clocks']
                    ? 'Múltiples'
                    : ($uniqueClocks->first() ?? $row['first_clock_name'] ?? 'Sin reloj');
                [$status, $label] = $this->summarizeGroupedStatus($row['status_summary']);
                $row['status'] = $status;
                $row['status_label'] = $label;
                $row['source_label'] = $this->summarizeGroupedSource($row['sources']);
                $row['type_label'] = $this->summarizeGroupedType($row['types']);
                $row['location_names'] = $uniqueLocations->all();
                $row['clock_names'] = $uniqueClocks->all();

                return $row;
            })
            ->sortByDesc(fn (array $row) => sprintf('%s %s', $row['local_date'], $row['last_check_display'] ?? '00:00:00'))
            ->values();
    }

    protected function applyTypeFilter(Builder $query, string $type): void
    {
        $normalized = strtolower(trim($type));

        if ($normalized === 'in') {
            $query->where('log_type', 1);

            return;
        }

        if ($normalized === 'out') {
            $query->whereIn('log_type', [2, 4]);

            return;
        }

        if ($normalized === 'unknown') {
            $query->where('log_type', 0);

            return;
        }

        if (ctype_digit($normalized)) {
            $query->where('log_type', (int) $normalized);
        }
    }

    protected function normalizeLogType(string $rawType): int
    {
        $value = strtolower(trim($rawType));

        if ($value === 'in') {
            return 1;
        }

        if ($value === 'out') {
            return 2;
        }

        if ($value === 'unknown') {
            return 0;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    protected function normalizeFilters(array $validated): array
    {
        $filters = $validated;

        if (empty($filters['from']) && empty($filters['to'])) {
            $filters['from'] = now()->subDays(6)->toDateString();
            $filters['to'] = now()->toDateString();
        }

        if (! empty($filters['from'])) {
            $filters['from'] = Carbon::parse($filters['from'])->toDateString();
        }

        if (! empty($filters['to'])) {
            $filters['to'] = Carbon::parse($filters['to'])->toDateString();
        }

        if (empty($filters['per_page'])) {
            $filters['per_page'] = 25;
        }

        $filters['view_mode'] = in_array(($filters['view_mode'] ?? 'grouped'), ['grouped', 'raw'], true)
            ? ($filters['view_mode'] ?? 'grouped')
            : 'grouped';

        if (! isset($filters['columns']) || ! is_array($filters['columns'])) {
            $filters['columns'] = [];
        }

        return $filters;
    }

    protected function rawRecordRelations(): array
    {
        $employeeColumns = ['id', 'full_name', 'name', 'fortia_employee_id'];

        foreach (['company_name', 'department_name', 'position_name'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $employeeColumns[] = $column;
            }
        }

        return [
            'employee:'.implode(',', $employeeColumns),
            'location:id,name,code,timezone',
            'clock:id,clock_name,serial_number,location_id',
            'clock.location:id,name,code,timezone',
        ];
    }

    protected function transformPaginatorMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem() ?? 0,
            'to' => $paginator->lastItem() ?? 0,
        ];
    }

    protected function attendanceColumnConfig(): array
    {
        return [
            'available' => [
                'grouped' => $this->columnsForMode('grouped'),
                'raw' => $this->columnsForMode('raw'),
            ],
            'default' => [
                'grouped' => $this->defaultColumnsForMode('grouped'),
                'raw' => $this->defaultColumnsForMode('raw'),
            ],
        ];
    }

    protected function columnsForMode(string $viewMode): array
    {
        if ($viewMode === 'raw') {
            return [
                ['key' => 'hora_local', 'label' => 'Hora local'],
                ['key' => 'fecha_local', 'label' => 'Fecha local'],
                ['key' => 'fecha_utc', 'label' => 'Fecha UTC'],
                ['key' => 'empleado', 'label' => 'Empleado'],
                ['key' => 'employee_id', 'label' => 'Empleado ID'],
                ['key' => 'fortia_employee_id', 'label' => 'Fortia ID'],
                ['key' => 'employee_code', 'label' => 'Clave empleado'],
                ['key' => 'unidad', 'label' => 'Unidad'],
                ['key' => 'reloj', 'label' => 'Reloj'],
                ['key' => 'tipo', 'label' => 'Tipo'],
                ['key' => 'fuente', 'label' => 'Fuente'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'empresa', 'label' => 'Empresa'],
                ['key' => 'departamento', 'label' => 'Departamento'],
                ['key' => 'puesto', 'label' => 'Puesto'],
                ['key' => 'created_at', 'label' => 'Creado'],
                ['key' => 'updated_at', 'label' => 'Actualizado'],
                ['key' => 'estado_validacion', 'label' => 'Estado validación'],
                ['key' => 'observaciones', 'label' => 'Observaciones'],
                ['key' => 'acciones', 'label' => 'Acciones', 'exportable' => false, 'locked' => true],
            ];
        }

        return [
            ['key' => 'empleado', 'label' => 'Empleado'],
            ['key' => 'fecha_local', 'label' => 'Hora local / Fecha local'],
            ['key' => 'primera_checada', 'label' => 'Primera checada'],
            ['key' => 'ultima_checada', 'label' => 'Última checada'],
            ['key' => 'unidad', 'label' => 'Unidad'],
            ['key' => 'reloj', 'label' => 'Reloj'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'employee_id', 'label' => 'Empleado ID'],
            ['key' => 'fortia_employee_id', 'label' => 'Fortia ID'],
            ['key' => 'employee_code', 'label' => 'Clave empleado'],
            ['key' => 'total_checadas', 'label' => 'Total checadas'],
            ['key' => 'entradas', 'label' => 'Entradas'],
            ['key' => 'salidas', 'label' => 'Salidas'],
            ['key' => 'fuente', 'label' => 'Fuente'],
            ['key' => 'tipo', 'label' => 'Tipo'],
            ['key' => 'primera_unidad', 'label' => 'Primera unidad'],
            ['key' => 'ultima_unidad', 'label' => 'Última unidad'],
            ['key' => 'primer_reloj', 'label' => 'Primer reloj'],
            ['key' => 'ultimo_reloj', 'label' => 'Último reloj'],
            ['key' => 'empresa', 'label' => 'Empresa'],
            ['key' => 'departamento', 'label' => 'Departamento'],
            ['key' => 'puesto', 'label' => 'Puesto'],
            ['key' => 'created_at', 'label' => 'Creado'],
            ['key' => 'updated_at', 'label' => 'Actualizado'],
            ['key' => 'estado_validacion', 'label' => 'Estado validación'],
            ['key' => 'observaciones', 'label' => 'Observaciones'],
            ['key' => 'acciones', 'label' => 'Acciones', 'exportable' => false, 'locked' => true],
        ];
    }

    protected function defaultColumnsForMode(string $viewMode): array
    {
        if ($viewMode === 'raw') {
            return ['hora_local', 'empleado', 'unidad', 'reloj', 'tipo', 'fuente', 'status', 'acciones'];
        }

        return ['empleado', 'fecha_local', 'primera_checada', 'ultima_checada', 'unidad', 'reloj', 'status', 'acciones'];
    }

    protected function normalizeRequestedColumns(array $requestedColumns, string $viewMode): array
    {
        $allowed = collect($this->columnsForMode($viewMode))
            ->filter(fn (array $column) => ($column['exportable'] ?? true) !== false)
            ->pluck('key')
            ->all();

        $columns = collect($requestedColumns)
            ->filter(fn ($key) => is_string($key) && in_array($key, $allowed, true))
            ->values()
            ->all();

        return $columns !== []
            ? $columns
            : collect($this->defaultColumnsForMode($viewMode))
                ->filter(fn (string $key) => in_array($key, $allowed, true))
                ->values()
                ->all();
    }

    protected function summarizeGroupedStatus(array $counts): array
    {
        $statuses = array_keys(array_filter($counts));

        if (count($statuses) <= 1) {
            $status = $statuses[0] ?? AttendanceRecord::STATUS_VALIDA;

            return [$status, $this->statusLabels()[$status] ?? ucfirst($status)];
        }

        return ['mixto', 'Mixto'];
    }

    protected function summarizeGroupedSource(array $counts): string
    {
        $keys = array_keys(array_filter($counts));

        if (count($keys) <= 1) {
            $key = $keys[0] ?? null;

            return $key ? ($this->sourceLabels()[$key] ?? strtoupper((string) $key)) : '—';
        }

        return 'Múltiples';
    }

    protected function summarizeGroupedType(array $counts): string
    {
        $keys = array_keys(array_filter($counts));

        if (count($keys) <= 1) {
            $key = $keys[0] ?? '0';

            return $this->resolveLogTypeLabel((int) $key);
        }

        return 'Múltiples';
    }

    protected function transformGroupedDetail(Collection $records, array $filters): array
    {
        /** @var AttendanceRecord $firstRecord */
        $firstRecord = $records->first();
        $grouped = $this->buildGroupedRows([
            ...$filters,
            'per_page' => max(1, $records->count()),
        ])->firstWhere('group_key', sprintf(
            'employee_%s_%s',
            $firstRecord->employee?->id ?? $firstRecord->employee_id,
            $this->resolveRecordLocalDate($firstRecord)?->toDateString() ?? Carbon::parse($filters['local_date'] ?? now())->toDateString(),
        ));

        $transformedRecords = $records
            ->map(fn (AttendanceRecord $record) => $this->transformIndexRecord($record))
            ->values();

        return [
            'employee' => [
                'id' => $firstRecord->employee?->id ?? $firstRecord->employee_id,
                'name' => $firstRecord->employee?->full_name ?? $firstRecord->employee?->name ?? 'N/A',
                'code' => (string) ($firstRecord->employee?->fortia_employee_id ?? $firstRecord->fortia_employee_id ?? $firstRecord->employee_id),
            ],
            'local_date' => $grouped['local_date'] ?? ($filters['local_date'] ?? null),
            'range' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'summary' => [
                'first_check_at' => $grouped['first_check_at'] ?? null,
                'first_check_display' => $grouped['first_check_display'] ?? null,
                'last_check_at' => $grouped['last_check_at'] ?? null,
                'last_check_display' => $grouped['last_check_display'] ?? null,
                'total_checks' => $grouped['total_checks'] ?? $records->count(),
                'entry_count' => $grouped['entry_count'] ?? 0,
                'exit_count' => $grouped['exit_count'] ?? 0,
                'units' => $grouped['location_names'] ?? [],
                'clocks' => $grouped['clock_names'] ?? [],
                'status' => $grouped['status_label'] ?? null,
            ],
            'records' => $transformedRecords,
        ];
    }

    protected function exportValuesForGroupedRow(array $row, array $columns): array
    {
        return collect($columns)->map(fn (string $column) => match ($column) {
            'empleado' => $row['employee_name'],
            'fecha_local' => $row['local_date_display'],
            'primera_checada' => $row['first_check_display'],
            'ultima_checada' => $row['last_check_display'],
            'unidad' => $row['location_name'],
            'reloj' => $row['clock_name'],
            'status', 'estado_validacion' => $row['status_label'],
            'employee_id' => $row['employee_id'],
            'fortia_employee_id' => $row['fortia_employee_id'],
            'employee_code' => $row['employee_code'],
            'total_checadas' => $row['total_checks'],
            'entradas' => $row['entry_count'],
            'salidas' => $row['exit_count'],
            'fuente' => $row['source_label'],
            'tipo' => $row['type_label'],
            'primera_unidad' => $row['first_location_name'],
            'ultima_unidad' => $row['last_location_name'],
            'primer_reloj' => $row['first_clock_name'],
            'ultimo_reloj' => $row['last_clock_name'],
            'empresa' => $row['company'],
            'departamento' => $row['department'],
            'puesto' => $row['position'],
            'created_at' => $row['created_at_display'],
            'updated_at' => $row['updated_at_display'],
            'observaciones' => $row['observation_summary'],
            default => null,
        })->all();
    }

    protected function exportValuesForRawRecord(array $row, array $columns): array
    {
        return collect($columns)->map(fn (string $column) => match ($column) {
            'hora_local' => $row['log_date_display'],
            'fecha_local' => substr((string) ($row['log_date_display'] ?? ''), 0, 10),
            'fecha_utc' => $row['log_date_utc_display'],
            'empleado' => $row['employee']['name'] ?? null,
            'employee_id' => $row['employee']['id'] ?? null,
            'fortia_employee_id', 'employee_code' => $row['employee']['code'] ?? null,
            'unidad' => $row['location']['name'] ?? null,
            'reloj' => $row['clock']['name'] ?? null,
            'tipo' => $row['log_type_label'] ?? null,
            'fuente' => $row['source_label'] ?? null,
            'status', 'estado_validacion' => $row['status_label'] ?? null,
            'empresa' => $row['company_name'] ?? null,
            'departamento' => $row['department_name'] ?? null,
            'puesto' => $row['position_name'] ?? null,
            'created_at' => $row['created_at_display'] ?? null,
            'updated_at' => $row['updated_at_display'] ?? null,
            'observaciones' => $row['adjustment_reason'] ?? null,
            default => null,
        })->all();
    }

    protected function resolveRecordLocationName(AttendanceRecord $record): string
    {
        return $record->location?->name
            ?? $record->clock?->location?->name
            ?? 'Sin unidad';
    }

    protected function resolveRecordClockName(AttendanceRecord $record): string
    {
        return $record->clock?->clock_name
            ?? ($record->device_id ? '#'.$record->device_id : 'Sin reloj');
    }

    protected function resolveEmployeeDepartment(AttendanceRecord $record): ?string
    {
        return $record->employee?->department_name ?? null;
    }

    protected function resolveEmployeePosition(AttendanceRecord $record): ?string
    {
        return $record->employee?->position_name ?? null;
    }

    protected function resolveEmployeeCompany(AttendanceRecord $record): ?string
    {
        return $record->employee?->company_name ?? null;
    }

    protected function storeAudit(
        Request $request,
        AttendanceRecord $attendance,
        string $action,
        ?string $reason,
        ?array $beforeData,
        ?array $afterData,
        ?int $changedById = null,
        ?string $changedByName = null,
        ?string $changedByEmail = null,
    ): void {
        $user = $request->user();

        AttendanceAudit::query()->create([
            'attendance_log_id' => $attendance->id,
            'action' => $action,
            'reason' => $reason,
            'changed_by_user_id' => $changedById ?? $user?->id,
            'changed_by_name' => $changedByName ?? $user?->name,
            'changed_by_email' => $changedByEmail ?? $user?->email,
            'before_data' => $beforeData,
            'after_data' => $afterData,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    protected function serializeRecord(AttendanceRecord $record): array
    {
        return Arr::only($record->toArray(), [
            'id',
            'log_id',
            'employee_id',
            'fortia_employee_id',
            'company_id',
            'location_id',
            'device_id',
            'log_date',
            'log_type',
            'source',
            'attendance_status',
            'adjustment_reason',
            'annulled_at',
            'annulled_by_user_id',
            'status',
        ]);
    }

    protected function resolveLogTypeLabel(int $logType): string
    {
        return $this->logTypeLabels()[$logType] ?? 'Desconocido';
    }

    protected function logTypeLabels(): array
    {
        return [
            0 => 'Desconocido',
            1 => 'IN',
            2 => 'OUT',
            3 => 'BREAK',
            4 => 'OUT',
        ];
    }

    protected function statusLabels(): array
    {
        return [
            AttendanceRecord::STATUS_VALIDA => 'Valida',
            AttendanceRecord::STATUS_ANULADA => 'Anulada',
            AttendanceRecord::STATUS_CORREGIDA => 'Corregida',
        ];
    }

    protected function sourceLabels(): array
    {
        return [
            AttendanceRecord::SOURCE_SYNC => 'Sync',
            AttendanceRecord::SOURCE_MANUAL => 'Manual',
            AttendanceRecord::SOURCE_IMPORT => 'Import',
            AttendanceRecord::SOURCE_API => 'API',
        ];
    }

    protected function transformIndexRecord(AttendanceRecord $record): array
    {
        $resolvedLocation = $record->location ?? $record->clock?->location;
        $timezone = $this->resolveRecordTimezone($record, $resolvedLocation?->timezone);
        $logDateLocal = $this->resolveRecordLocalDate($record, $timezone);
        $logDateUtc = $this->resolveRecordUtcDate($record);

        return [
            'id' => $record->id,
            'log_id' => $record->log_id,
            'log_date' => $record->log_date?->toIso8601String(),
            'log_date_local' => $logDateLocal?->toIso8601String(),
            'log_date_display' => $logDateLocal?->format('Y-m-d H:i:s'),
            'log_date_timezone' => $timezone,
            'log_date_utc_display' => $logDateUtc?->format('Y-m-d H:i:s'),
            'log_type' => (int) $record->log_type,
            'log_type_label' => $this->resolveLogTypeLabel((int) $record->log_type),
            'source' => $record->source,
            'source_label' => $this->sourceLabels()[$record->source] ?? strtoupper((string) $record->source),
            'attendance_status' => $record->attendance_status,
            'status_label' => $this->statusLabels()[$record->attendance_status] ?? ucfirst((string) $record->attendance_status),
            'adjustment_reason' => $record->adjustment_reason,
            'company_name' => $record->employee?->company_name,
            'department_name' => $record->employee?->department_name,
            'position_name' => $record->employee?->position_name,
            'created_at' => $record->created_at?->toIso8601String(),
            'created_at_display' => $this->convertToTimezone($record->created_at, $timezone)?->format('Y-m-d H:i:s'),
            'updated_at' => $record->updated_at?->toIso8601String(),
            'updated_at_display' => $this->convertToTimezone($record->updated_at, $timezone)?->format('Y-m-d H:i:s'),
            'employee' => [
                'id' => $record->employee?->id ?? $record->employee_id,
                'name' => $record->employee?->full_name ?? $record->employee?->name ?? 'N/A',
                'code' => (string) ($record->employee?->fortia_employee_id ?? $record->employee_id ?? 'N/A'),
            ],
            'location' => [
                'id' => $resolvedLocation?->id ?? $record->location_id ?? $record->clock?->location_id,
                'name' => $resolvedLocation?->name ?? 'Sin unidad',
                'code' => $resolvedLocation?->code,
                'timezone' => $timezone,
            ],
            'clock' => [
                'id' => $record->clock?->id ?? $record->device_id,
                'name' => $record->clock?->clock_name ?? ('#'.($record->device_id ?? 'N/A')),
                'serial_number' => $record->clock?->serial_number,
                'location_id' => $record->clock?->location_id,
            ],
        ];
    }

    protected function transformShowRecord(AttendanceRecord $record): array
    {
        return [
            ...$this->transformIndexRecord($record),
            'company_id' => $record->company_id,
            'fortia_employee_id' => $record->fortia_employee_id,
            'device_id' => $record->device_id,
            'raw_payload' => $record->raw_payload,
            'fortia_response_payload' => $record->fortia_response_payload,
            'annulled_at' => $record->annulled_at?->toIso8601String(),
            'annulled_at_display' => $record->annulled_at?->format('Y-m-d H:i:s'),
            'annulled_by_user_id' => $record->annulled_by_user_id,
            'annulled_by' => [
                'id' => $record->annulledBy?->id ?? $record->annulled_by_user_id,
                'name' => $record->annulledBy?->name ?? ($record->annulled_by_user_id ? 'Usuario #'.$record->annulled_by_user_id : null),
                'email' => $record->annulledBy?->email,
            ],
            'fortia_status' => $record->fortia_status,
            'sent_to_fortia_at' => $record->sent_to_fortia_at?->toIso8601String(),
            'sent_to_fortia_at_display' => $record->sent_to_fortia_at?->format('Y-m-d H:i:s'),
            'audits' => $record->audits
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (AttendanceAudit $audit) => [
                    'id' => $audit->id,
                    'created_at' => $audit->created_at?->toIso8601String(),
                    'created_at_display' => $this->convertToTimezone(
                        $audit->created_at,
                        ($record->location ?? $record->clock?->location)?->timezone ?: config('app.timezone', 'UTC'),
                    )?->format('Y-m-d H:i:s'),
                    'action' => $audit->action,
                    'reason' => $audit->reason,
                    'changed_by_name' => $audit->changed_by_name ?? $audit->changedBy?->name,
                    'changed_by_email' => $audit->changed_by_email ?? $audit->changedBy?->email,
                    'before_data' => $audit->before_data,
                    'after_data' => $audit->after_data,
                ])
                ->all(),
        ];
    }

    private function convertToTimezone(?Carbon $dateTime, ?string $timezone): ?Carbon
    {
        if (! $dateTime) {
            return null;
        }

        $resolvedTimezone = $this->normalizeTimezone($timezone);

        try {
            return $dateTime->copy()->setTimezone($resolvedTimezone);
        } catch (\Throwable $exception) {
            return $dateTime->copy()->setTimezone($this->attendanceFallbackTimezone());
        }
    }

    private function resolveRecordTimezone(AttendanceRecord $record, ?string $fallbackTimezone = null): string
    {
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $rawTimezone = $rawPayload['timezone']
            ?? $rawPayload['tz']
            ?? null;

        return $this->normalizeTimezone(
            is_string($rawTimezone) ? $rawTimezone : null,
            $fallbackTimezone
        );
    }

    private function resolveRecordLocalDate(AttendanceRecord $record, ?string $timezone = null): ?Carbon
    {
        $tz = $this->normalizeTimezone($timezone);
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $rawLocal = $rawPayload['punched_at_local']
            ?? $rawPayload['event_time_local']
            ?? null;

        if ($localDate = $this->parseDateTimeInTimezone($rawLocal, $tz)) {
            return $localDate;
        }

        $rawUtc = $rawPayload['punched_at_utc']
            ?? $rawPayload['event_time_utc']
            ?? null;

        if ($utcDate = $this->parseUtcDateTimeForTimezone($rawUtc, $tz)) {
            return $utcDate;
        }

        return $this->convertToTimezone($record->log_date, $tz);
    }

    private function resolveRecordUtcDate(AttendanceRecord $record): ?Carbon
    {
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $rawUtc = $rawPayload['punched_at_utc']
            ?? $rawPayload['event_time_utc']
            ?? null;

        if ($utcDate = $this->parseUtcDateTimeForTimezone($rawUtc, 'UTC')) {
            return $utcDate;
        }

        return $record->log_date?->copy()->utc();
    }

    private function parseDateTimeInTimezone(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable $exception) {
            try {
                return Carbon::parse($value)->setTimezone($timezone);
            } catch (\Throwable $exception) {
                return null;
            }
        }
    }

    private function parseUtcDateTimeForTimezone(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->setTimezone($timezone);
        } catch (\Throwable $exception) {
            try {
                return Carbon::parse($value)->utc()->setTimezone($timezone);
            } catch (\Throwable $exception) {
                return null;
            }
        }
    }

    private function normalizeTimezone(?string $timezone, ?string $fallbackTimezone = null): string
    {
        foreach ([$timezone, $fallbackTimezone, $this->attendanceFallbackTimezone()] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $trimmedCandidate = trim($candidate);

            try {
                new \DateTimeZone($trimmedCandidate);

                return $trimmedCandidate;
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return $this->attendanceFallbackTimezone();
    }

    private function attendanceFallbackTimezone(): string
    {
        return 'America/Mexico_City';
    }
}
