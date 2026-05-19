<?php

namespace App\Services\Dashboard;

use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Employee;
use App\Models\EmployeeSyncState;
use App\Models\Location;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardSummaryService
{
    public const ONLINE_THRESHOLD_MINUTES = 5;
    public const PRIORITY_LOCATION_LIMIT = 6;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $timezone = config('app.timezone', 'UTC');
        $range = (string) ($filters['range'] ?? 'today');
        $companyId = isset($filters['company_id']) && $filters['company_id'] !== '' ? (int) $filters['company_id'] : null;
        $unitId = isset($filters['unit_id']) && $filters['unit_id'] !== '' ? (int) $filters['unit_id'] : null;
        $employeeBaseLocationId = $unitId ? $this->resolveEmployeeBaseLocationId($unitId) : null;

        [$from, $to] = $this->resolveRange(
            $range,
            isset($filters['from_date']) ? (string) $filters['from_date'] : null,
            isset($filters['to_date']) ? (string) $filters['to_date'] : null,
            $timezone,
        );

        $entryTypes = collect(config('attendance.consolidation.entry_log_types', [1]))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
        $exitTypes = collect(config('attendance.consolidation.exit_log_types', [2, 4]))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $attendanceBase = $this->attendanceQuery($from, $to, $companyId, $unitId);
        $activeEmployeesQuery = $this->activeEmployeesQuery($companyId, $employeeBaseLocationId);
        $clocksBase = $this->clocksQuery($companyId, $unitId);
        $onlineThreshold = now()->subMinutes(self::ONLINE_THRESHOLD_MINUTES);

        $employeesActive = (clone $activeEmployeesQuery)->count();
        $attendanceRegistered = (clone $attendanceBase)
            ->whereNotNull('employee_id')
            ->distinct('employee_id')
            ->count('employee_id');
        $pendingAttendance = max($employeesActive - $attendanceRegistered, 0);
        $attendanceCoverage = $this->percentage($attendanceRegistered, $employeesActive);
        $entriesTotal = $this->countAttendanceByTypes(clone $attendanceBase, $entryTypes);
        $exitsTotal = $this->countAttendanceByTypes(clone $attendanceBase, $exitTypes);
        $validLogsTotal = (clone $attendanceBase)->count();
        $latestAttendanceAt = (clone $attendanceBase)->max('log_date');

        $clockMonitoringCounts = (clone $clocksBase)
            ->select('monitoring_status', DB::raw('COUNT(*) as total'))
            ->groupBy('monitoring_status')
            ->get()
            ->keyBy(fn ($row) => (string) ($row->monitoring_status ?? 'offline'));

        $clocksTotal = (clone $clocksBase)->count();
        $clocksOnline = (clone $clocksBase)
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>', $onlineThreshold)
            ->count();
        $clocksOffline = (clone $clocksBase)
            ->where(function (Builder $query) use ($onlineThreshold): void {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<=', $onlineThreshold);
            })
            ->count();
        $clocksWarning = (int) ($clockMonitoringCounts->get('warning')->total ?? 0);
        $clocksNeverConnected = (clone $clocksBase)->whereNull('last_heartbeat_at')->count();
        $heartbeatRecent = $clocksOnline;
        $heartbeatStale = max($clocksTotal - $heartbeatRecent, 0);
        $clocksStaleOnly = (clone $clocksBase)
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<=', $onlineThreshold)
            ->where(function (Builder $query): void {
                $query->whereNull('monitoring_status')
                    ->orWhere('monitoring_status', '!=', 'offline');
            })
            ->count();
        $clocksOfflineOnly = max($clocksTotal - $clocksOnline - $clocksStaleOnly, 0);
        $lastReportingClock = $this->resolveLastReportingClock(clone $clocksBase);

        $employeeStatus = $this->buildEmployeeStatusDataset($companyId, $employeeBaseLocationId);
        $clockHealth = $this->buildClockHealthDataset($clocksOnline, $clocksWarning, $clocksOffline);
        $presenceSeries = $this->buildPresenceSeries($from, $to, clone $attendanceBase);
        $hourlyActivity = $this->buildHourlyActivity(clone $attendanceBase, $entryTypes, $exitTypes);

        $locations = $this->buildLocationRanking(
            $companyId,
            $unitId,
            clone $attendanceBase,
            $onlineThreshold
        );
        $priorityLocations = $this->buildPriorityLocations($locations);

        $enrollment = $this->buildEnrollmentSummary($companyId, $employeeBaseLocationId);
        $syncState = $this->resolveLatestSyncState();
        $clockStatus = $this->resolveClockStatus(
            $clocksTotal,
            $clocksOnline,
            $clocksOffline,
            $clocksWarning,
            $heartbeatStale
        );
        $alerts = $this->buildAlerts(
            employeesActive: $employeesActive,
            pendingAttendance: $pendingAttendance,
            attendanceCoverage: $attendanceCoverage,
            clocksTotal: $clocksTotal,
            clocksOnline: $clocksOnline,
            clocksOffline: $clocksOffline,
            clocksNeverConnected: $clocksNeverConnected,
            heartbeatStale: $heartbeatStale,
            locations: $locations,
            enrollment: $enrollment,
            syncState: $syncState,
        );
        $recentActivity = $this->buildRecentActivity(clone $attendanceBase, $timezone);
        $topBranches = $this->buildTopBranchesChart($locations);
        $executiveStatus = $this->buildExecutiveStatus(
            employeesActive: $employeesActive,
            attendanceRegistered: $attendanceRegistered,
            pendingAttendance: $pendingAttendance,
            attendanceCoverage: $attendanceCoverage,
            validLogsTotal: $validLogsTotal,
            latestAttendanceAt: $latestAttendanceAt,
            clocksTotal: $clocksTotal,
            clocksOnline: $clocksOnline,
            clocksOffline: $clocksOffline,
            clocksWarning: $clocksWarning,
            locations: $locations,
            alerts: $alerts,
            enrollment: $enrollment,
            timezone: $timezone,
        );

        $meta = [
            'range' => $range,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'company_id' => $companyId,
            'unit_id' => $unitId,
            'generated_at_local' => now($timezone)->format('d/m/Y H:i:s'),
            'generated_at_iso' => now($timezone)->toIso8601String(),
            'latest_attendance_at' => $latestAttendanceAt ? Carbon::parse($latestAttendanceAt)->toIso8601String() : null,
        ];

        $summary = [
            'employees_active' => $employeesActive,
            'attendance_registered' => $attendanceRegistered,
            'attendance_pending' => $pendingAttendance,
            'attendance_coverage' => $attendanceCoverage,
            'entries_total' => $entriesTotal,
            'exits_total' => $exitsTotal,
            'latest_log_at' => $latestAttendanceAt ? Carbon::parse($latestAttendanceAt)->toIso8601String() : null,
            'last_updated_at' => $meta['generated_at_iso'],
        ];

        $clocks = [
            'total' => $clocksTotal,
            'online' => $clocksOnline,
            'offline' => $clocksOffline,
            'warning' => $clocksWarning,
            'heartbeat_recent' => $heartbeatRecent,
            'heartbeat_stale' => $heartbeatStale,
            'never_connected' => $clocksNeverConnected,
            'last_reporting_clock' => $lastReportingClock,
            'status' => $clockStatus['status'],
            'status_label' => $clockStatus['label'],
            'status_reason' => $clockStatus['reason'],
            'online_threshold_minutes' => self::ONLINE_THRESHOLD_MINUTES,
        ];

        $kpis = [
            'checkins_total' => $validLogsTotal,
            'employees_active' => $employeesActive,
            'clocks_with_alerts' => $clocksWarning,
            'clocks_offline' => $clocksOffline,
        ];

        $charts = [
            'attendance_donut' => [
                'present' => $attendanceRegistered,
                'pending' => $pendingAttendance,
                'percentage' => $attendanceCoverage,
            ],
            'clocks_donut' => [
                'online' => $clocksOnline,
                'offline' => $clocksOfflineOnly,
                'stale' => $clocksStaleOnly,
            ],
            'hourly_activity' => $hourlyActivity,
            'enrollment' => [
                'with_any_biometric' => (int) ($enrollment['with_any_biometric'] ?? 0),
                'without_any_biometric' => (int) ($enrollment['without_any_biometric'] ?? 0),
                'without_fingerprint' => (int) ($enrollment['without_fingerprint'] ?? 0),
                'without_face' => (int) ($enrollment['without_face'] ?? 0),
                'percentage' => (float) ($enrollment['coverage_percentage'] ?? 0),
            ],
            'people_present_by_day' => $presenceSeries,
            'employees_status' => $employeeStatus,
            'clock_health' => $clockHealth,
            'top_branches' => $topBranches,
        ];

        $isEmpty = $employeesActive === 0
            && $clocksTotal === 0
            && $validLogsTotal === 0
            && $locations->isEmpty();

        return [
            'ok' => true,
            'empty' => $isEmpty,
            'message' => $isEmpty ? 'No hay datos operativos para el rango seleccionado.' : null,
            'meta' => $meta,
            'summary' => $summary,
            'clocks' => $clocks,
            'executive_status' => $executiveStatus,
            'alerts' => $alerts->values()->all(),
            'locations' => $priorityLocations->values()->all(),
            'locations_meta' => [
                'total' => $locations->count(),
                'shown' => $priorityLocations->count(),
                'has_more' => $locations->count() > $priorityLocations->count(),
                'mode' => 'priority',
                'message' => 'Mostrando unidades que requieren mayor atencion',
                'limit' => self::PRIORITY_LOCATION_LIMIT,
            ],
            'recent_activity' => $recentActivity->values()->all(),
            'enrollment' => $enrollment,
            'kpis' => $kpis,
            'charts' => $charts,
        ];
    }

    protected function attendanceQuery(
        Carbon $from,
        Carbon $to,
        ?int $companyId,
        ?int $unitId
    ): Builder {
        return AttendanceLog::query()
            ->whereBetween('log_date', [$from, $to])
            ->where(function (Builder $query): void {
                $query->whereNull('attendance_status')
                    ->orWhere('attendance_status', '!=', 'anulada');
            })
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($unitId, fn (Builder $query) => $query->where('location_id', $unitId));
    }

    protected function activeEmployeesQuery(?int $companyId, ?int $employeeBaseLocationId): Builder
    {
        return Employee::query()
            ->whereIn('status', ['A', 'ACTIVE', 'active'])
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($employeeBaseLocationId, fn (Builder $query) => $query->where('base_location_id', $employeeBaseLocationId));
    }

    protected function clocksQuery(?int $companyId, ?int $unitId): Builder
    {
        return Clock::query()
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($unitId, fn (Builder $query) => $query->where('location_id', $unitId));
    }

    /**
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    protected function resolveRange(
        string $range,
        ?string $fromInput,
        ?string $toInput,
        string $timezone
    ): array {
        $now = now($timezone);

        if ($range === 'custom' && $fromInput && $toInput) {
            return [
                Carbon::createFromFormat('d/m/Y', $fromInput, $timezone)->startOfDay(),
                Carbon::createFromFormat('d/m/Y', $toInput, $timezone)->endOfDay(),
            ];
        }

        return match ($range) {
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    protected function countAttendanceByTypes(Builder $query, array $types): int
    {
        return $types === [] ? 0 : $query->whereIn('log_type', $types)->count();
    }

    protected function percentage(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }

    protected function buildPresenceSeries(Carbon $from, Carbon $to, Builder $attendanceBase): array
    {
        $records = $attendanceBase
            ->selectRaw('DATE(log_date) as day')
            ->selectRaw('COUNT(DISTINCT employee_id) as total')
            ->whereNotNull('employee_id')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $map = $records->pluck('total', 'day');
        $labels = [];
        $values = [];

        foreach (new CarbonPeriod($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay()) as $date) {
            $key = $date->toDateString();
            $labels[] = $date->format('d/m');
            $values[] = (int) ($map[$key] ?? 0);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    protected function buildEmployeeStatusDataset(?int $companyId, ?int $employeeBaseLocationId): array
    {
        $rows = Employee::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($employeeBaseLocationId, fn (Builder $query) => $query->where('base_location_id', $employeeBaseLocationId))
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => strtoupper((string) $row->status));

        $active = (int) (($rows['A']->total ?? 0) + ($rows['ACTIVE']->total ?? 0));
        $inactive = max($rows->sum('total') - $active, 0);

        return [
            'labels' => ['Activos', 'Bajas'],
            'values' => [$active, $inactive],
        ];
    }

    protected function buildClockHealthDataset(int $online, int $warning, int $offline): array
    {
        return [
            'labels' => ['En linea', 'Con alertas', 'Sin conexion'],
            'values' => [$online, $warning, $offline],
        ];
    }

    /**
     * @param  array<int, int>  $entryTypes
     * @param  array<int, int>  $exitTypes
     * @return array<int, array<string, int|string>>
     */
    protected function buildHourlyActivity(Builder $attendanceBase, array $entryTypes, array $exitTypes): array
    {
        $hourExpression = $this->hourBucketExpression('log_date');
        $entrySql = $entryTypes === [] ? '0' : 'SUM(CASE WHEN log_type IN ('.implode(',', $entryTypes).') THEN 1 ELSE 0 END)';
        $exitSql = $exitTypes === [] ? '0' : 'SUM(CASE WHEN log_type IN ('.implode(',', $exitTypes).') THEN 1 ELSE 0 END)';

        $records = $attendanceBase
            ->selectRaw($hourExpression.' as hour_key')
            ->selectRaw($entrySql.' as entries')
            ->selectRaw($exitSql.' as exits')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hour_key')
            ->orderBy('hour_key')
            ->get()
            ->keyBy(fn ($row) => str_pad((string) $row->hour_key, 2, '0', STR_PAD_LEFT));

        $hours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $record = $records->get($hourKey);

            $hours[] = [
                'hour' => $hourKey.':00',
                'entries' => (int) ($record->entries ?? 0),
                'exits' => (int) ($record->exits ?? 0),
                'total' => (int) ($record->total ?? 0),
            ];
        }

        return $hours;
    }

    protected function buildEnrollmentSummary(?int $companyId, ?int $employeeBaseLocationId): array
    {
        $activeEmployees = $this->activeEmployeesQuery($companyId, $employeeBaseLocationId);
        $employeesActive = (clone $activeEmployees)->count();
        $withoutFingerprint = (clone $activeEmployees)
            ->where(function (Builder $query): void {
                $query->whereNull('has_fingerprint')
                    ->orWhere('has_fingerprint', false);
            })
            ->count();

        $supportsFace = $this->employeesSupportFaceFields();
        $withoutFace = $supportsFace
            ? (clone $activeEmployees)
                ->where(function (Builder $query): void {
                    $query->whereNull('has_face_enrollment')
                        ->orWhere('has_face_enrollment', false);
                })
                ->count()
            : 0;

        $withoutAnyBiometric = $supportsFace
            ? (clone $activeEmployees)
                ->where(function (Builder $query): void {
                    $query->where(function (Builder $fingerprint): void {
                        $fingerprint->whereNull('has_fingerprint')
                            ->orWhere('has_fingerprint', false);
                    })->where(function (Builder $face): void {
                        $face->whereNull('has_face_enrollment')
                            ->orWhere('has_face_enrollment', false);
                    });
                })
                ->count()
            : $withoutFingerprint;

        $withAnyBiometric = max($employeesActive - $withoutAnyBiometric, 0);

        return [
            'employees_active' => $employeesActive,
            'without_fingerprint' => $withoutFingerprint,
            'without_face' => $withoutFace,
            'without_any_biometric' => $withoutAnyBiometric,
            'with_any_biometric' => $withAnyBiometric,
            'coverage_percentage' => $this->percentage($withAnyBiometric, $employeesActive),
        ];
    }

    protected function employeesSupportFaceFields(): bool
    {
        return DB::getSchemaBuilder()->hasColumn('employees', 'has_face_enrollment');
    }

    protected function buildLocationRanking(
        ?int $companyId,
        ?int $unitId,
        Builder $attendanceBase,
        Carbon $onlineThreshold
    ): Collection {
        $attendanceByLocation = $attendanceBase
            ->select('location_id')
            ->selectRaw('COUNT(DISTINCT employee_id) as attendance_registered')
            ->whereNotNull('location_id')
            ->whereNotNull('employee_id')
            ->groupBy('location_id')
            ->get()
            ->keyBy('location_id');

        return Location::query()
            ->select('id', 'name', 'code', 'company_id')
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($unitId, fn (Builder $query) => $query->whereKey($unitId))
            ->withCount([
                'employees as employees_active_count' => function (Builder $query): void {
                    $query->whereIn('status', ['A', 'ACTIVE', 'active']);
                },
                'clocks as clocks_total_count',
                'clocks as clocks_online_count' => function (Builder $query) use ($onlineThreshold): void {
                    $query->whereNotNull('last_heartbeat_at')
                        ->where('last_heartbeat_at', '>', $onlineThreshold);
                },
                'clocks as clocks_offline_count' => function (Builder $query) use ($onlineThreshold): void {
                    $query->where(function (Builder $offline) use ($onlineThreshold): void {
                        $offline->whereNull('last_heartbeat_at')
                            ->orWhere('last_heartbeat_at', '<=', $onlineThreshold);
                    });
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Location $location) use ($attendanceByLocation): array {
                $attendanceRegistered = (int) ($attendanceByLocation->get($location->id)->attendance_registered ?? 0);
                $employeesActive = (int) ($location->employees_active_count ?? 0);
                $coverage = $this->percentage($attendanceRegistered, $employeesActive);
                $status = $this->resolveLocationStatus(
                    $employeesActive,
                    $attendanceRegistered,
                    $coverage,
                    (int) ($location->clocks_total_count ?? 0),
                    (int) ($location->clocks_offline_count ?? 0)
                );

                return [
                    'id' => (int) $location->id,
                    'name' => $location->name ?: 'Unidad #'.$location->id,
                    'code' => $location->code,
                    'employees_active' => $employeesActive,
                    'attendance_registered' => $attendanceRegistered,
                    'attendance_coverage' => $coverage,
                    'clocks_total' => (int) ($location->clocks_total_count ?? 0),
                    'clocks_online' => (int) ($location->clocks_online_count ?? 0),
                    'clocks_offline' => (int) ($location->clocks_offline_count ?? 0),
                    'status' => $status['status'],
                    'status_label' => $status['label'],
                ];
            })
            ->sort(function (array $left, array $right): int {
                $priority = [
                    'critical' => 0,
                    'warning' => 1,
                    'normal' => 2,
                    'nodata' => 3,
                ];

                $leftPriority = $priority[$left['status']] ?? 99;
                $rightPriority = $priority[$right['status']] ?? 99;

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                return strcmp((string) $left['name'], (string) $right['name']);
            })
            ->values();
    }

    protected function buildPriorityLocations(Collection $locations): Collection
    {
        return $locations
            ->sort(function (array $left, array $right): int {
                $priority = [
                    'critical' => 0,
                    'warning' => 1,
                    'nodata' => 2,
                    'normal' => 3,
                ];

                $leftPriority = $priority[$left['status']] ?? 99;
                $rightPriority = $priority[$right['status']] ?? 99;

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                $leftCoverage = (float) ($left['attendance_coverage'] ?? 0);
                $rightCoverage = (float) ($right['attendance_coverage'] ?? 0);

                if ($leftCoverage !== $rightCoverage) {
                    return $leftCoverage <=> $rightCoverage;
                }

                $leftOffline = (int) ($left['clocks_offline'] ?? 0);
                $rightOffline = (int) ($right['clocks_offline'] ?? 0);

                if ($leftOffline !== $rightOffline) {
                    return $rightOffline <=> $leftOffline;
                }

                $leftEmployees = (int) ($left['employees_active'] ?? 0);
                $rightEmployees = (int) ($right['employees_active'] ?? 0);

                if ($leftEmployees !== $rightEmployees) {
                    return $rightEmployees <=> $leftEmployees;
                }

                return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            })
            ->take(self::PRIORITY_LOCATION_LIMIT)
            ->values();
    }

    protected function resolveLocationStatus(
        int $employeesActive,
        int $attendanceRegistered,
        float $coverage,
        int $clocksTotal,
        int $clocksOffline
    ): array {
        if ($employeesActive === 0 && $clocksTotal === 0) {
            return ['status' => 'nodata', 'label' => 'Sin datos'];
        }

        if ($clocksTotal === 0) {
            return ['status' => 'nodata', 'label' => 'Sin datos'];
        }

        if ($employeesActive === 0) {
            return $clocksOffline > 0
                ? ['status' => 'warning', 'label' => 'Atencion']
                : ['status' => 'nodata', 'label' => 'Sin datos'];
        }

        if ($clocksOffline > 0 || ($employeesActive > 0 && $attendanceRegistered === 0)) {
            return ['status' => 'critical', 'label' => 'Critico'];
        }

        if (($employeesActive > 0 && $coverage < 80) || ($clocksTotal > 0 && $coverage < 95)) {
            return ['status' => 'warning', 'label' => 'Atencion'];
        }

        return ['status' => 'normal', 'label' => 'Normal'];
    }

    protected function resolveClockStatus(
        int $clocksTotal,
        int $clocksOnline,
        int $clocksOffline,
        int $clocksWarning,
        int $heartbeatStale
    ): array {
        if ($clocksTotal === 0) {
            return [
                'status' => 'nodata',
                'label' => 'Sin datos',
                'reason' => 'No hay relojes configurados.',
            ];
        }

        if ($clocksOnline === 0 || $clocksOffline >= max(1, (int) ceil($clocksTotal * 0.5))) {
            return [
                'status' => 'critical',
                'label' => 'Alerta critica',
                'reason' => 'La mayor parte de los relojes no esta reportando.',
            ];
        }

        if ($clocksOffline > 0 || $clocksWarning > 0 || $heartbeatStale > 0) {
            return [
                'status' => 'warning',
                'label' => 'Atencion preventiva',
                'reason' => 'Hay relojes con senales de riesgo operativo.',
            ];
        }

        return [
            'status' => 'normal',
            'label' => 'Operacion normal',
            'reason' => 'Todos los relojes reportan dentro del umbral esperado.',
        ];
    }

    protected function buildAlerts(
        int $employeesActive,
        int $pendingAttendance,
        float $attendanceCoverage,
        int $clocksTotal,
        int $clocksOnline,
        int $clocksOffline,
        int $clocksNeverConnected,
        int $heartbeatStale,
        Collection $locations,
        array $enrollment,
        ?array $syncState
    ): Collection {
        $alerts = collect();

        if ($clocksTotal > 0 && $clocksOnline === 0) {
            $alerts->push($this->makeAlert(
                'critical',
                'Sin heartbeat reciente',
                'Ningun reloj biometrico ha reportado dentro de los ultimos '.self::ONLINE_THRESHOLD_MINUTES.' minutos.',
                $clocksOffline
            ));
        } elseif ($clocksOffline > 0) {
            $alerts->push($this->makeAlert(
                'critical',
                'Relojes sin conexion',
                $clocksOffline.' reloj(es) no han reportado dentro del umbral operativo.',
                $clocksOffline
            ));
        }

        if ($heartbeatStale > 0 && $clocksOnline > 0) {
            $alerts->push($this->makeAlert(
                'warning',
                'Heartbeat con rezago',
                $heartbeatStale.' reloj(es) tienen mas de '.self::ONLINE_THRESHOLD_MINUTES.' minutos sin actividad.',
                $heartbeatStale
            ));
        }

        $locationsWithoutAttendance = $locations
            ->filter(fn (array $row) => $row['employees_active'] > 0 && $row['attendance_registered'] === 0)
            ->values();

        if ($locationsWithoutAttendance->isNotEmpty()) {
            $alerts->push($this->makeAlert(
                'critical',
                'Unidades sin asistencia',
                $locationsWithoutAttendance->count().' unidad(es) activas no registran asistencias en el periodo.',
                $locationsWithoutAttendance->count()
            ));
        }

        if ($clocksNeverConnected > 0) {
            $alerts->push($this->makeAlert(
                'warning',
                'Relojes nunca conectados',
                $clocksNeverConnected.' reloj(es) estan registrados pero nunca han reportado heartbeat.',
                $clocksNeverConnected
            ));
        }

        if (($enrollment['without_any_biometric'] ?? 0) > 0) {
            $alerts->push($this->makeAlert(
                'warning',
                'Empleados sin biometria',
                $enrollment['without_any_biometric'].' empleado(s) activos no tienen huella ni Face ID.',
                (int) $enrollment['without_any_biometric']
            ));
        }

        if ($employeesActive > 0 && $attendanceCoverage < 70) {
            $alerts->push($this->makeAlert(
                'warning',
                'Cobertura baja de asistencia',
                'La cobertura de asistencia esta en '.$attendanceCoverage.'% frente al personal activo.',
                $pendingAttendance
            ));
        }

        if ($syncState && isset($syncState['minutes_since_success']) && $syncState['minutes_since_success'] > 180) {
            $alerts->push($this->makeAlert(
                'info',
                'Sincronizacion antigua',
                'La ultima sincronizacion exitosa de empleados fue hace mas de 3 horas.',
                (int) $syncState['minutes_since_success']
            ));
        }

        return $alerts
            ->sort(function (array $left, array $right): int {
                $priority = [
                    'critical' => 0,
                    'warning' => 1,
                    'info' => 2,
                ];

                $leftPriority = $priority[$left['level']] ?? 99;
                $rightPriority = $priority[$right['level']] ?? 99;

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                return ((int) ($right['metric'] ?? 0)) <=> ((int) ($left['metric'] ?? 0));
            })
            ->take(8)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeAlert(string $level, string $title, string $message, int $metric): array
    {
        return [
            'id' => md5($level.'|'.$title.'|'.$message),
            'level' => $level,
            'title' => $title,
            'message' => $message,
            'metric' => $metric,
        ];
    }

    protected function buildExecutiveStatus(
        int $employeesActive,
        int $attendanceRegistered,
        int $pendingAttendance,
        float $attendanceCoverage,
        int $validLogsTotal,
        mixed $latestAttendanceAt,
        int $clocksTotal,
        int $clocksOnline,
        int $clocksOffline,
        int $clocksWarning,
        Collection $locations,
        Collection $alerts,
        array $enrollment,
        string $timezone
    ): array {
        $criticalLocations = $locations->where('status', 'critical')->count();
        $warningLocations = $locations->where('status', 'warning')->count();

        $level = 'normal';

        if (
            ($clocksTotal > 0 && $clocksOnline === 0)
            || $clocksOffline > 0
            || ($employeesActive > 0 && $attendanceCoverage < 50)
            || $criticalLocations > 0
        ) {
            $level = 'critical';
        } elseif (
            $clocksWarning > 0
            || ($employeesActive > 0 && $attendanceCoverage < 85)
            || $warningLocations > 0
            || $alerts->where('level', 'warning')->isNotEmpty()
        ) {
            $level = 'warning';
        } elseif ($employeesActive === 0 && $clocksTotal === 0 && $validLogsTotal === 0) {
            $level = 'nodata';
        }

        $title = match ($level) {
            'critical' => 'Operacion critica',
            'warning' => 'Operacion con atencion',
            'nodata' => 'Sin datos operativos',
            default => 'Operacion normal',
        };

        $message = match ($level) {
            'critical' => 'Operacion critica: '.$this->joinFragments(array_filter([
                $clocksOffline > 0 ? $clocksOffline.' reloj(es) sin conexion' : null,
                $employeesActive > 0 ? 'cobertura de asistencia en '.$attendanceCoverage.'%' : null,
                $criticalLocations > 0 ? $criticalLocations.' unidad(es) en estado critico' : null,
            ])),
            'warning' => 'Seguimiento preventivo: '.$this->joinFragments(array_filter([
                $clocksWarning > 0 ? $clocksWarning.' reloj(es) requieren atencion' : null,
                $employeesActive > 0 ? 'cobertura de asistencia en '.$attendanceCoverage.'%' : null,
                $warningLocations > 0 ? $warningLocations.' unidad(es) con riesgo operativo' : null,
            ])),
            'nodata' => 'No hay datos operativos suficientes para evaluar el periodo seleccionado.',
            default => 'Operacion estable: '.$this->joinFragments(array_filter([
                $attendanceRegistered > 0 ? $attendanceRegistered.' empleado(s) con asistencia' : 'sin asistencias registradas',
                $clocksTotal > 0 ? $clocksOnline.' reloj(es) en linea' : 'sin relojes configurados',
            ])),
        };

        $bullets = [];
        $clocksAttention = min($clocksOffline + $clocksWarning, $clocksTotal);

        if ($clocksTotal > 0) {
            $bullets[] = $clocksAttention.' de '.$clocksTotal.' relojes requieren atencion.';
        } else {
            $bullets[] = 'No hay relojes configurados para el filtro actual.';
        }

        if ($employeesActive > 0) {
            $bullets[] = $attendanceRegistered.' de '.$employeesActive.' empleados activos registraron asistencia en el periodo.';
        } else {
            $bullets[] = 'No hay empleados activos para el filtro actual.';
        }

        if (($enrollment['without_any_biometric'] ?? 0) > 0) {
            $bullets[] = $enrollment['without_any_biometric'].' empleado(s) activos no tienen ningun metodo biometrico.';
        } else {
            $bullets[] = 'La cobertura biometrica no presenta pendientes criticos.';
        }

        if ($latestAttendanceAt) {
            $bullets[] = 'Ultima asistencia detectada el '.Carbon::parse($latestAttendanceAt)->timezone($timezone)->format('d/m/Y H:i').'.';
        } else {
            $bullets[] = 'No se detectaron asistencias recientes.';
        }

        if ($pendingAttendance > 0 && $employeesActive > 0) {
            $bullets[] = $pendingAttendance.' empleado(s) siguen pendientes de asistencia.';
        }

        return [
            'level' => $level,
            'title' => $title,
            'message' => rtrim($message, '.').'.',
            'bullets' => collect($bullets)->take(5)->values()->all(),
        ];
    }

    protected function joinFragments(array $parts): string
    {
        if ($parts === []) {
            return 'sin incidencias relevantes';
        }

        if (count($parts) === 1) {
            return (string) $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' y '.$last;
    }

    protected function resolveLatestSyncState(): ?array
    {
        try {
            $state = EmployeeSyncState::query()
                ->orderByDesc('last_success_at')
                ->orderByDesc('last_synced_at')
                ->first();

            if (! $state) {
                return null;
            }

            $lastSuccessAt = $state->last_success_at ?? $state->last_synced_at;

            return [
                'source' => $state->source,
                'last_sync_status' => $state->last_sync_status,
                'last_synced_at' => optional($state->last_synced_at)?->toIso8601String(),
                'last_success_at' => optional($lastSuccessAt)?->toIso8601String(),
                'minutes_since_success' => $lastSuccessAt ? $lastSuccessAt->diffInMinutes(now()) : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function buildRecentActivity(Builder $attendanceBase, string $timezone): Collection
    {
        return $attendanceBase
            ->with([
                'employee:id,full_name,name',
                'location:id,name',
                'clock:id,clock_name,serial_number',
            ])
            ->orderByDesc('log_date')
            ->limit(12)
            ->get()
            ->map(function (AttendanceLog $log) use ($timezone): array {
                $occurredAt = $log->log_date?->copy()?->timezone($timezone);

                return [
                    'id' => (int) $log->id,
                    'employee_name' => $log->employee?->full_name ?? $log->employee?->name ?? 'Empleado sin nombre',
                    'unit_name' => $log->location?->name ?? 'Sin unidad',
                    'clock_name' => $log->clock?->clock_name ?? ($log->clock?->serial_number ? 'Reloj '.$log->clock->serial_number : 'Sin reloj'),
                    'event_type' => $this->formatLogType((int) $log->log_type),
                    'log_type' => (int) $log->log_type,
                    'method' => $this->resolveAttendanceMethod($log),
                    'source' => $log->source ?? 'sync',
                    'occurred_at' => $occurredAt?->toIso8601String(),
                    'occurred_at_display' => $occurredAt?->format('d/m/Y H:i:s'),
                ];
            });
    }

    protected function resolveAttendanceMethod(AttendanceLog $log): string
    {
        if (($log->source ?? null) === 'manual') {
            return 'Manual';
        }

        $rawPayload = is_array($log->raw_payload) ? $log->raw_payload : [];
        $candidates = collect([
            $rawPayload['meta']['biometric_type'] ?? null,
            $rawPayload['meta']['method'] ?? null,
            $rawPayload['source'] ?? null,
            $rawPayload['provider'] ?? null,
        ])->filter()->map(fn ($value) => strtolower((string) $value))->values();

        if ($candidates->contains(fn (string $value) => str_contains($value, 'face') || str_contains($value, 'rostro') || str_contains($value, 'camera'))) {
            return 'Rostro';
        }

        if ($candidates->contains(fn (string $value) => str_contains($value, 'finger') || str_contains($value, 'huella') || str_contains($value, 'scanner'))) {
            return 'Huella';
        }

        if (($log->source ?? null) === 'api') {
            return 'Biometrico';
        }

        return ucfirst((string) ($log->source ?? 'Otro'));
    }

    protected function formatLogType(int $value): string
    {
        return match ($value) {
            1 => 'Entrada',
            2 => 'Salida',
            3 => 'Break',
            4 => 'Regreso',
            default => 'Desconocido',
        };
    }

    protected function buildTopBranchesChart(Collection $locations): array
    {
        $topLocations = $locations
            ->sortByDesc('attendance_registered')
            ->take(5)
            ->values();

        return [
            'mode' => 'attendance',
            'labels' => $topLocations->pluck('name')->all(),
            'values' => $topLocations->pluck('attendance_registered')->map(fn ($value) => (int) $value)->all(),
        ];
    }

    protected function resolveLastReportingClock(Builder $clocksBase): ?array
    {
        $clock = $clocksBase
            ->with('location:id,name')
            ->whereNotNull('last_heartbeat_at')
            ->orderByDesc('last_heartbeat_at')
            ->first();

        if (! $clock) {
            return null;
        }

        return [
            'id' => (int) $clock->id,
            'name' => $clock->clock_name ?: 'Reloj #'.$clock->id,
            'serial_number' => $clock->serial_number,
            'location_name' => $clock->location?->name,
            'last_heartbeat_at' => optional($clock->last_heartbeat_at)?->toIso8601String(),
            'monitoring_status' => $clock->monitoring_status ?? 'offline',
        ];
    }

    protected function resolveEmployeeBaseLocationId(int $unitId): ?int
    {
        if ($unitId <= 0) {
            return null;
        }

        $locationQuery = Location::query()
            ->select('id', 'fortia_location_id', 'code')
            ->whereKey($unitId);

        if (DB::getSchemaBuilder()->hasColumn('locations', 'fortia_location_id')) {
            $locationQuery->orWhere('fortia_location_id', $unitId);
        }

        if (DB::getSchemaBuilder()->hasColumn('locations', 'code')) {
            $locationQuery->orWhere('code', (string) $unitId);
        }

        $location = $locationQuery->first();

        if (! $location) {
            return null;
        }

        if (is_numeric($location->fortia_location_id)) {
            return (int) $location->fortia_location_id;
        }

        if (is_numeric($location->code)) {
            return (int) $location->code;
        }

        return $unitId;
    }

    protected function hourBucketExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%H', {$column})",
            default => "LPAD(HOUR({$column}), 2, '0')",
        };
    }
}
