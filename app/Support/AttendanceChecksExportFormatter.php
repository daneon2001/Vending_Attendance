<?php

namespace App\Support;

use App\Models\AttendanceRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AttendanceChecksExportFormatter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableColumns(bool $includeTechnicalColumns = false): array
    {
        $columns = [
            ['key' => 'empleado', 'label' => 'Empleado'],
            ['key' => 'fortia_employee_id', 'label' => 'Fortia ID'],
            ['key' => 'employee_code', 'label' => 'Clave empleado'],
            ['key' => 'fecha_local', 'label' => 'Fecha local'],
            ['key' => 'hora_local', 'label' => 'Hora local'],
            ['key' => 'unidad', 'label' => 'Unidad'],
            ['key' => 'reloj', 'label' => 'Reloj'],
            ['key' => 'serie_reloj', 'label' => 'Serie reloj'],
            ['key' => 'tipo', 'label' => 'Tipo'],
            ['key' => 'fuente', 'label' => 'Fuente'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'observaciones', 'label' => 'Observaciones'],
            ['key' => 'employee_id', 'label' => 'Empleado ID'],
            ['key' => 'empresa', 'label' => 'Empresa'],
            ['key' => 'departamento', 'label' => 'Departamento'],
            ['key' => 'puesto', 'label' => 'Puesto'],
            ['key' => 'created_at', 'label' => 'Creado'],
            ['key' => 'updated_at', 'label' => 'Actualizado'],
            ['key' => 'remote_id', 'label' => 'Remote ID'],
            ['key' => 'local_event_id', 'label' => 'Local event ID'],
        ];

        if ($includeTechnicalColumns) {
            $columns[] = ['key' => 'fortia_status', 'label' => 'Fortia status'];
            $columns[] = ['key' => 'payload_tecnico', 'label' => 'Payload tecnico'];
        }

        return $columns;
    }

    /**
     * @return array<int, string>
     */
    public function defaultColumns(): array
    {
        return [
            'empleado',
            'fortia_employee_id',
            'employee_code',
            'fecha_local',
            'hora_local',
            'unidad',
            'reloj',
            'serie_reloj',
            'tipo',
            'fuente',
            'status',
            'observaciones',
        ];
    }

    /**
     * @param  array<int, mixed>  $requestedColumns
     * @return array<int, string>
     */
    public function normalizeRequestedColumns(array $requestedColumns, bool $includeTechnicalColumns = false): array
    {
        $allowed = collect($this->availableColumns($includeTechnicalColumns))
            ->pluck('key')
            ->all();

        $columns = collect($requestedColumns)
            ->filter(fn ($key) => is_string($key) && in_array($key, $allowed, true))
            ->values()
            ->all();

        return $columns !== []
            ? $columns
            : collect($this->defaultColumns())
                ->filter(fn (string $key) => in_array($key, $allowed, true))
                ->values()
                ->all();
    }

    /**
     * @return array<int, string>
     */
    public function headings(array $columns, bool $includeTechnicalColumns = false): array
    {
        $map = collect($this->availableColumns($includeTechnicalColumns))->keyBy('key');

        return collect($columns)
            ->map(fn (string $key) => $map[$key]['label'] ?? $key)
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    public function exportValuesForRecord(AttendanceRecord $record, array $columns): array
    {
        $row = $this->transformRecord($record);

        return collect($columns)
            ->map(fn (string $column) => $row[$column] ?? null)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function transformRecord(AttendanceRecord $record): array
    {
        $resolvedLocation = $record->location ?? $record->clock?->location;
        $timezone = $record->attendanceOperationsTimezone();
        $logDateLocal = $record->resolvedAttendanceLocalDateTime();

        return [
            'empleado' => $record->employee?->full_name ?? $record->employee?->name ?? 'N/A',
            'fortia_employee_id' => (string) ($record->employee?->fortia_employee_id ?? $record->fortia_employee_id ?? ''),
            'employee_code' => (string) ($record->employee?->fortia_employee_id ?? $record->fortia_employee_id ?? $record->employee_id ?? ''),
            'fecha_local' => $logDateLocal?->format('Y-m-d'),
            'hora_local' => $logDateLocal?->format('H:i:s'),
            'unidad' => $resolvedLocation?->name ?? 'Sin unidad',
            'reloj' => $record->clock?->clock_name ?? ($record->device_id ? '#'.$record->device_id : 'Sin reloj'),
            'serie_reloj' => $record->clock?->serial_number ?? $record->device_serial,
            'tipo' => $this->resolveLogTypeLabel((int) $record->log_type),
            'fuente' => $this->sourceLabels()[$record->source] ?? strtoupper((string) $record->source),
            'status' => $this->statusLabels()[$record->attendance_status] ?? ucfirst((string) $record->attendance_status),
            'observaciones' => $record->adjustment_reason,
            'employee_id' => $record->employee?->id ?? $record->employee_id,
            'empresa' => $record->employee?->company_name,
            'departamento' => $record->employee?->department_name,
            'puesto' => $record->employee?->position_name,
            'created_at' => $this->convertToTimezone($record->created_at, $timezone)?->format('Y-m-d H:i:s'),
            'updated_at' => $this->convertToTimezone($record->updated_at, $timezone)?->format('Y-m-d H:i:s'),
            'remote_id' => $record->log_id,
            'local_event_id' => $record->local_id,
            'fortia_status' => $record->fortia_status,
            'payload_tecnico' => $this->stringifyPayload($record->raw_payload),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function employeeRelationColumns(): array
    {
        $columns = ['id', 'full_name', 'name', 'fortia_employee_id'];

        foreach (['company_name', 'department_name', 'position_name'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    protected function stringifyPayload(mixed $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (is_array($payload)) {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return is_scalar($payload) ? (string) $payload : null;
    }

    protected function resolveLogTypeLabel(int $logType): string
    {
        return match ($logType) {
            1 => 'IN',
            2, 4 => 'OUT',
            3 => 'BREAK',
            default => 'Desconocido',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function statusLabels(): array
    {
        return [
            AttendanceRecord::STATUS_VALIDA => 'Valida',
            AttendanceRecord::STATUS_ANULADA => 'Anulada',
            AttendanceRecord::STATUS_CORREGIDA => 'Corregida',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function sourceLabels(): array
    {
        return [
            AttendanceRecord::SOURCE_SYNC => 'Sync',
            AttendanceRecord::SOURCE_MANUAL => 'Manual',
            AttendanceRecord::SOURCE_IMPORT => 'Import',
            AttendanceRecord::SOURCE_API => 'API',
        ];
    }

    protected function convertToTimezone(?Carbon $dateTime, ?string $timezone): ?Carbon
    {
        if (! $dateTime) {
            return null;
        }

        $resolvedTimezone = $this->normalizeTimezone($timezone);

        try {
            return $dateTime->copy()->setTimezone($resolvedTimezone);
        } catch (\Throwable) {
            return $dateTime->copy()->setTimezone($this->attendanceFallbackTimezone());
        }
    }

    protected function normalizeTimezone(?string $timezone, ?string $fallbackTimezone = null): string
    {
        foreach ([$timezone, $fallbackTimezone, $this->attendanceFallbackTimezone()] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $trimmedCandidate = trim($candidate);

            try {
                new \DateTimeZone($trimmedCandidate);

                return $trimmedCandidate;
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->attendanceFallbackTimezone();
    }

    protected function attendanceFallbackTimezone(): string
    {
        return 'America/Mexico_City';
    }
}
