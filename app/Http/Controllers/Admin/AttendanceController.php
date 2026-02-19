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
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(AttendanceFilterRequest $request): Response
    {
        $filters = $this->normalizeFilters($request->validated());
        $perPage = (int) ($filters['per_page'] ?? 25);

        $records = $this->buildFilteredQuery($filters)
            ->with([
                'employee:id,full_name,name,fortia_employee_id',
                'location:id,name,code,timezone',
                'clock:id,clock_name,serial_number,location_id',
                'clock.location:id,name,code,timezone',
            ])
            ->orderByDesc('log_date')
            ->paginate($perPage)
            ->withQueryString();

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
            'initialRecords' => [
                'data' => $records->getCollection()
                    ->map(fn (AttendanceRecord $record) => $this->transformIndexRecord($record))
                    ->values(),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'from' => $records->firstItem() ?? 0,
                    'to' => $records->lastItem() ?? 0,
                ],
            ],
            'filters' => $filters,
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

            $record = AttendanceRecord::query()->create([
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
            ]);

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
        $filenameBase = 'central_asistencias_'.now()->format('Ymd_His');

        $records = $this->buildFilteredQuery($filters)
            ->with([
                'employee:id,full_name,name,fortia_employee_id',
                'location:id,name,code,timezone',
                'clock:id,clock_name,serial_number,location_id',
                'clock.location:id,name,code,timezone',
            ])
            ->orderByDesc('log_date')
            ->get();

        $headers = [
            'FechaHora',
            'Empleado',
            'CodigoEmpleado',
            'Unidad',
            'Reloj',
            'Tipo',
            'Fuente',
            'Estatus',
            'Motivo',
            'LogId',
            'RegistroId',
        ];

        $streamCallback = function () use ($records, $headers): void {
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, $headers);

            foreach ($records as $record) {
                $resolvedLocation = $record->location ?? $record->clock?->location;
                $timezone = $this->resolveRecordTimezone($record, $resolvedLocation?->timezone);
                $logDateLocal = $this->resolveRecordLocalDate($record, $timezone);

                fputcsv($output, [
                    optional($logDateLocal)->format('Y-m-d H:i:s'),
                    $record->employee?->full_name ?? $record->employee?->name ?? 'N/A',
                    $record->employee?->fortia_employee_id ?? $record->employee_id,
                    $resolvedLocation?->name ?? 'N/A',
                    $record->clock?->clock_name ?? $record->device_id,
                    $this->resolveLogTypeLabel((int) $record->log_type),
                    $this->sourceLabels()[$record->source] ?? $record->source,
                    $this->statusLabels()[$record->attendance_status] ?? $record->attendance_status,
                    $record->adjustment_reason,
                    $record->log_id,
                    $record->id,
                ]);
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

        return $filters;
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

        return [
            'id' => $record->id,
            'log_id' => $record->log_id,
            'log_date' => $record->log_date?->toIso8601String(),
            'log_date_display' => $logDateLocal?->format('Y-m-d H:i:s'),
            'log_date_timezone' => $timezone,
            'log_type' => (int) $record->log_type,
            'log_type_label' => $this->resolveLogTypeLabel((int) $record->log_type),
            'source' => $record->source,
            'source_label' => $this->sourceLabels()[$record->source] ?? strtoupper((string) $record->source),
            'attendance_status' => $record->attendance_status,
            'status_label' => $this->statusLabels()[$record->attendance_status] ?? ucfirst((string) $record->attendance_status),
            'adjustment_reason' => $record->adjustment_reason,
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

        try {
            return $dateTime->copy()->setTimezone($timezone ?: config('app.timezone', 'UTC'));
        } catch (\Throwable $exception) {
            return $dateTime->copy()->setTimezone(config('app.timezone', 'UTC'));
        }
    }

    private function resolveRecordTimezone(AttendanceRecord $record, ?string $fallbackTimezone = null): string
    {
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $rawTimezone = $rawPayload['timezone']
            ?? $rawPayload['tz']
            ?? null;

        if (is_string($rawTimezone) && trim($rawTimezone) !== '') {
            return trim($rawTimezone);
        }

        return $fallbackTimezone ?: config('app.timezone', 'UTC');
    }

    private function resolveRecordLocalDate(AttendanceRecord $record, ?string $timezone = null): ?Carbon
    {
        $tz = $timezone ?: config('app.timezone', 'UTC');
        $rawPayload = is_array($record->raw_payload) ? $record->raw_payload : [];
        $rawLocal = $rawPayload['punched_at_local']
            ?? $rawPayload['event_time_local']
            ?? null;

        if (is_string($rawLocal) && trim($rawLocal) !== '') {
            try {
                return Carbon::parse($rawLocal, $tz);
            } catch (\Throwable $exception) {
                try {
                    return Carbon::parse($rawLocal)->setTimezone($tz);
                } catch (\Throwable $exception) {
                    // Fallback below.
                }
            }
        }

        return $this->convertToTimezone($record->log_date, $tz);
    }
}
