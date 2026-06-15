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
    public const TAB_SUMMARY = 'resumen';
    public const TAB_CLOCKS = 'relojes';
    public const TAB_LOCATIONS = 'unidades';
    public const TAB_ACTIVITY = 'actividad';
    public const TAB_ENROLLMENT = 'enrolamiento';
    public const TAB_ALERTS = 'alertas';
    public const ONLINE_THRESHOLD_MINUTES = 5;
    public const PRIORITY_LOCATION_LIMIT = 6;
    public const RECENT_ACTIVITY_LIMIT = 5;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $timezone = $this->operationsTimezone();
        $storageTimezone = $this->storageTimezone();
        $isBusinessHours = $this->isBusinessHours($timezone);
        $activeTab = $this->normalizeTab(isset($filters['tab']) ? (string) $filters['tab'] : null);
        $range = (string) ($filters['range'] ?? 'today');
        $companyId = isset($filters['company_id']) && $filters['company_id'] !== '' ? (int) $filters['company_id'] : null;
        $unitId = isset($filters['unit_id']) && $filters['unit_id'] !== '' ? (int) $filters['unit_id'] : null;
        $employeeBaseLocationId = $unitId ? $this->resolveEmployeeBaseLocationId($unitId) : null;

        [$fromLocal, $toLocal] = $this->resolveRange(
            $range,
            isset($filters['from_date']) ? (string) $filters['from_date'] : null,
            isset($filters['to_date']) ? (string) $filters['to_date'] : null,
            $timezone,
        );
        $from = $this->toStorageTimezone($fromLocal, $storageTimezone);
        $to = $this->toStorageTimezone($toLocal, $storageTimezone);

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
        $onlineThreshold = now($storageTimezone)->subMinutes(self::ONLINE_THRESHOLD_MINUTES);

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
        $clockStatus = $this->resolveClockStatus(
            $clocksTotal,
            $clocksOnline,
            $clocksOffline,
            $clocksWarning,
            $heartbeatStale,
            $isBusinessHours
        );
        $lastReportingClock = $this->shouldIncludeLastReportingClock($activeTab)
            ? $this->resolveLastReportingClock(clone $clocksBase)
            : null;

        $locations = $this->shouldIncludeLocations($activeTab)
            ? $this->buildLocationRanking(
                $companyId,
                $unitId,
                clone $attendanceBase,
                $onlineThreshold
            )
            : collect();
        $priorityLocations = $locations->isNotEmpty()
            ? $this->buildPriorityLocations($locations)
            : collect();

        $enrollment = $this->shouldIncludeEnrollment($activeTab)
            ? $this->buildEnrollmentSummary($companyId, $employeeBaseLocationId)
            : null;

        $syncState = $this->shouldIncludeAlerts($activeTab)
            ? $this->resolveLatestSyncState($timezone, $storageTimezone)
            : null;

        $alerts = $this->shouldIncludeAlerts($activeTab)
            ? $this->buildAlerts(
                employeesActive: $employeesActive,
                pendingAttendance: $pendingAttendance,
                attendanceCoverage: $attendanceCoverage,
                clocksTotal: $clocksTotal,
                clocksOnline: $clocksOnline,
                clocksOffline: $clocksOffline,
                clocksNeverConnected: $clocksNeverConnected,
                heartbeatStale: $heartbeatStale,
                locations: $locations,
                enrollment: $enrollment ?? $this->emptyEnrollmentSummary(),
                syncState: $syncState,
                isBusinessHours: $isBusinessHours,
            )
            : collect();

        $connectivityAlerts = $this->shouldIncludeConnectivityAlerts($activeTab)
            ? $this->buildConnectivityAlerts(
                clocksTotal: $clocksTotal,
                clocksOnline: $clocksOnline,
                clocksOffline: $clocksOffline,
                clocksNeverConnected: $clocksNeverConnected,
                heartbeatStale: $heartbeatStale,
                isBusinessHours: $isBusinessHours,
            )
            : collect();

        $executiveStatus = $this->shouldIncludeExecutiveStatus($activeTab)
            ? $this->buildExecutiveStatus(
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
                enrollment: $enrollment ?? $this->emptyEnrollmentSummary(),
                timezone: $timezone,
                isBusinessHours: $isBusinessHours,
            )
            : null;

        $recentActivity = $this->includesTab($activeTab, self::TAB_ACTIVITY)
            ? $this->buildRecentActivity(clone $attendanceBase, $timezone)
            : collect();

        $presenceSeries = $this->includesTab($activeTab, self::TAB_SUMMARY)
            ? $this->buildPresenceSeries($fromLocal, $toLocal, clone $attendanceBase, $timezone, $storageTimezone)
            : null;

        $employeeStatus = $this->includesTab($activeTab, self::TAB_SUMMARY)
            ? $this->buildEmployeeStatusDataset($companyId, $employeeBaseLocationId)
            : null;

        $hourlyActivity = ($this->includesTab($activeTab, self::TAB_ACTIVITY) || $this->includesTab($activeTab, self::TAB_SUMMARY))
            ? $this->buildHourlyActivity(clone $attendanceBase, $entryTypes, $exitTypes, $timezone, $storageTimezone)
            : null;

        $topBranches = $this->includesTab($activeTab, self::TAB_LOCATIONS) && $locations->isNotEmpty()
            ? $this->buildTopBranchesChart($locations)
            : null;

        $meta = [
            'range' => $range,
            'from' => $fromLocal->toIso8601String(),
            'to' => $toLocal->toIso8601String(),
            'company_id' => $companyId,
            'unit_id' => $unitId,
            'generated_at_local' => now($timezone)->format('d/m/Y H:i:s'),
            'generated_at_iso' => now($timezone)->toIso8601String(),
            'latest_attendance_at' => $this->toOperationsIsoString($latestAttendanceAt, $timezone, $storageTimezone),
        ];

        $summary = [
            'employees_active' => $employeesActive,
            'attendance_registered' => $attendanceRegistered,
            'attendance_pending' => $pendingAttendance,
            'attendance_coverage' => $attendanceCoverage,
            'entries_total' => $entriesTotal,
            'exits_total' => $exitsTotal,
            'latest_log_at' => $this->toOperationsIsoString($latestAttendanceAt, $timezone, $storageTimezone),
            'last_updated_at' => $meta['generated_at_iso'],
        ];

        $clocks = [
            'total' => $clocksTotal,
            'online' => $clocksOnline,
            'offline' => $clocksOffline,
            'warning' => $clocksWarning,
            'heartbeat_recent' => $heartbeatRecent,
            'heartbeat_stale' => $heartbeatStale,
            'stale' => $heartbeatStale,
            'never_connected' => $clocksNeverConnected,
            'last_reporting_clock' => $lastReportingClock,
            'status' => $clockStatus['status'],
            'status_label' => $clockStatus['label'],
            'status_reason' => $clockStatus['reason'],
            'operational_status' => $clockStatus['status'],
            'operational_note' => $clockStatus['reason'],
            'is_business_hours' => $isBusinessHours,
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
        ];

        $isEmpty = $employeesActive === 0
            && $clocksTotal === 0
            && $validLogsTotal === 0;

        $response = [
            'ok' => true,
            'active_tab' => $activeTab ?? self::TAB_SUMMARY,
            'empty' => $isEmpty,
            'message' => $isEmpty ? 'No hay datos operativos para el rango seleccionado.' : null,
            'timezone' => [
                'name' => $timezone,
                'label' => $this->operationsTimezoneLabel(),
                'offset' => $this->timezoneOffsetString($timezone),
            ],
            'meta' => $meta,
            'summary' => $summary,
            'clocks' => $clocks,
            'connectivity_alerts' => $connectivityAlerts->values()->all(),
            'kpis' => $kpis,
            'charts' => $charts,
            'executive_summary' => $this->buildExecutiveSummary(
                employeesActive: $employeesActive,
                attendanceRegistered: $attendanceRegistered,
                pendingAttendance: $pendingAttendance,
                attendanceCoverage: $attendanceCoverage,
                entriesTotal: $entriesTotal,
                exitsTotal: $exitsTotal,
                latestAttendanceAt: $latestAttendanceAt,
                timezone: $timezone,
                storageTimezone: $storageTimezone,
                enrollment: $enrollment ?? $this->emptyEnrollmentSummary(),
                clocks: $clocks,
                hourlyActivity: $hourlyActivity ?? [],
                alerts: $alerts,
                isBusinessHours: $isBusinessHours,
            ),
        ];

        if ($executiveStatus !== null) {
            $response['executive_status'] = $executiveStatus;
        }

        if ($alerts->isNotEmpty() || $this->shouldIncludeAlerts($activeTab)) {
            $response['alerts'] = $alerts->values()->all();
        }

        if ($enrollment !== null) {
            $response['enrollment'] = $enrollment;
            $response['charts']['enrollment'] = [
                'with_any_biometric' => (int) ($enrollment['with_any_biometric'] ?? 0),
                'without_any_biometric' => (int) ($enrollment['without_any_biometric'] ?? 0),
                'without_fingerprint' => (int) ($enrollment['without_fingerprint'] ?? 0),
                'without_face' => (int) ($enrollment['without_face'] ?? 0),
                'percentage' => (float) ($enrollment['coverage_percentage'] ?? 0),
            ];
        }

        if ($this->shouldIncludeLocations($activeTab)) {
            $response['locations'] = $priorityLocations->values()->all();
            $response['locations_meta'] = [
                'total' => $locations->count(),
                'shown' => $priorityLocations->count(),
                'has_more' => $locations->count() > $priorityLocations->count(),
                'mode' => 'priority',
                'message' => 'Mostrando unidades que requieren mayor atencion',
                'limit' => self::PRIORITY_LOCATION_LIMIT,
            ];
        }

        if ($recentActivity->isNotEmpty() || $this->includesTab($activeTab, self::TAB_ACTIVITY)) {
            $response['recent_activity'] = $recentActivity->values()->all();
            $response['charts']['hourly_activity'] = $hourlyActivity ?? [];
        }

        if ($presenceSeries !== null) {
            $response['charts']['people_present_by_day'] = $presenceSeries;
        }

        if ($employeeStatus !== null) {
            $response['charts']['employees_status'] = $employeeStatus;
        }

        if ($topBranches !== null) {
            $response['charts']['top_branches'] = $topBranches;
        }

        return $response;
    }

    protected function normalizeTab(?string $tab): ?string
    {
        if ($tab === null || trim($tab) === '') {
            return null;
        }

        $normalized = strtolower(trim($tab));

        return in_array($normalized, [
            self::TAB_SUMMARY,
            self::TAB_CLOCKS,
            self::TAB_LOCATIONS,
            self::TAB_ACTIVITY,
            self::TAB_ENROLLMENT,
            self::TAB_ALERTS,
        ], true)
            ? $normalized
            : null;
    }

    protected function includesTab(?string $activeTab, string $tab): bool
    {
        return $activeTab === null || $activeTab === $tab;
    }

    protected function shouldIncludeLocations(?string $activeTab): bool
    {
        return $this->includesTab($activeTab, self::TAB_SUMMARY)
            || $this->includesTab($activeTab, self::TAB_LOCATIONS)
            || $this->includesTab($activeTab, self::TAB_ALERTS);
    }

    protected function shouldIncludeEnrollment(?string $activeTab): bool
    {
        return $this->includesTab($activeTab, self::TAB_SUMMARY)
            || $this->includesTab($activeTab, self::TAB_ENROLLMENT)
            || $this->includesTab($activeTab, self::TAB_ALERTS);
    }

    protected function shouldIncludeAlerts(?string $activeTab): bool
    {
        return $this->includesTab($activeTab, self::TAB_SUMMARY)
            || $this->includesTab($activeTab, self::TAB_ALERTS);
    }

    protected function shouldIncludeConnectivityAlerts(?string $activeTab): bool
    {
        return $this->includesTab($activeTab, self::TAB_SUMMARY)
            || $this->includesTab($activeTab, self::TAB_CLOCKS);
    }

    protected function shouldIncludeExecutiveStatus(?string $activeTab): bool
    {
        return $this->includesTab($activeTab, self::TAB_SUMMARY)
            || $this->includesTab($activeTab, self::TAB_ALERTS);
    }

    protected function shouldIncludeLastReportingClock(?string $activeTab): bool
    {
        return $this->includesTab($activeTab, self::TAB_SUMMARY)
            || $this->includesTab($activeTab, self::TAB_CLOCKS);
    }

    /**
     * @return array<string, int|float>
     */
    protected function emptyEnrollmentSummary(): array
    {
        return [
            'employees_active' => 0,
            'with_fingerprint' => 0,
            'with_face' => 0,
            'with_both_biometrics' => 0,
            'without_fingerprint' => 0,
            'without_face' => 0,
            'without_any_biometric' => 0,
            'with_any_biometric' => 0,
            'coverage_percent' => 0,
            'coverage_percentage' => 0,
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

    protected function buildPresenceSeries(
        Carbon $from,
        Carbon $to,
        Builder $attendanceBase,
        string $timezone,
        string $storageTimezone
    ): array
    {
        $dayExpression = $this->dateBucketExpression('log_date', $timezone, $storageTimezone);

        $records = $attendanceBase
            ->selectRaw($dayExpression.' as day')
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
    protected function buildHourlyActivity(
        Builder $attendanceBase,
        array $entryTypes,
        array $exitTypes,
        string $timezone,
        string $storageTimezone
    ): array
    {
        $hourExpression = $this->hourBucketExpression('log_date', $timezone, $storageTimezone);
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
        $withFingerprint = (clone $activeEmployees)
            ->where('has_fingerprint', true)
            ->count();
        $withoutFingerprint = (clone $activeEmployees)
            ->where(function (Builder $query): void {
                $query->whereNull('has_fingerprint')
                    ->orWhere('has_fingerprint', false);
            })
            ->count();

        $supportsFace = $this->employeesSupportFaceFields();
        $withFace = $supportsFace
            ? (clone $activeEmployees)
                ->where('has_face_enrollment', true)
                ->count()
            : 0;
        $withoutFace = $supportsFace
            ? (clone $activeEmployees)
                ->where(function (Builder $query): void {
                    $query->whereNull('has_face_enrollment')
                        ->orWhere('has_face_enrollment', false);
                })
                ->count()
            : 0;

        $withBoth = $supportsFace
            ? (clone $activeEmployees)
                ->where('has_fingerprint', true)
                ->where('has_face_enrollment', true)
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
            'with_fingerprint' => $withFingerprint,
            'with_face' => $withFace,
            'with_both_biometrics' => $withBoth,
            'without_fingerprint' => $withoutFingerprint,
            'without_face' => $withoutFace,
            'without_any_biometric' => $withoutAnyBiometric,
            'with_any_biometric' => $withAnyBiometric,
            'coverage_percent' => $this->percentage($withAnyBiometric, $employeesActive),
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
        int $heartbeatStale,
        bool $isBusinessHours
    ): array {
        if ($clocksTotal === 0) {
            return [
                'status' => 'nodata',
                'label' => 'Sin datos',
                'reason' => 'No hay relojes configurados.',
            ];
        }

        if (! $isBusinessHours) {
            return [
                'status' => 'info',
                'label' => 'Fuera de horario operativo',
                'reason' => 'Fuera de horario operativo: conectividad informativa.',
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
        ?array $syncState,
        bool $isBusinessHours
    ): Collection {
        $alerts = collect();

        if ($isBusinessHours) {
            if ($clocksTotal > 0 && $clocksOnline === 0) {
                $alerts->push($this->makeAlert(
                    'critical',
                    'Sin heartbeat reciente',
                    'Ningun reloj biometrico ha reportado dentro de los ultimos '.self::ONLINE_THRESHOLD_MINUTES.' minutos.',
                    $clocksOffline,
                    'Validar conectividad, energia y enlace del administrador on-premise.'
                ));
            } elseif ($clocksOffline > 0) {
                $alerts->push($this->makeAlert(
                    'critical',
                    'Relojes sin conexion',
                    $clocksOffline.' reloj(es) no han reportado dentro del umbral operativo.',
                    $clocksOffline,
                    'Revisar relojes, red local y heartbeat del sitio.'
                ));
            }

            if ($heartbeatStale > 0 && $clocksOnline > 0) {
                $alerts->push($this->makeAlert(
                    'warning',
                    'Heartbeat con rezago',
                    $heartbeatStale.' reloj(es) tienen mas de '.self::ONLINE_THRESHOLD_MINUTES.' minutos sin actividad.',
                    $heartbeatStale,
                    'Verificar latencia o reinicio preventivo de los equipos con rezago.'
                ));
            }
        } elseif ($clocksOffline > 0 || $heartbeatStale > 0) {
            $alerts->push($this->makeAlert(
                'info',
                'Conectividad fuera de horario',
                'Fuera de horario operativo: conectividad informativa.',
                max($clocksOffline, $heartbeatStale),
                'Monitorear nuevamente durante el siguiente horario operativo.'
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
                $locationsWithoutAttendance->count(),
                'Confirmar asistencia, cobertura de checadas y disponibilidad de reloj por unidad.'
            ));
        }

        if ($clocksNeverConnected > 0) {
            $alerts->push($this->makeAlert(
                'warning',
                'Relojes nunca conectados',
                $clocksNeverConnected.' reloj(es) estan registrados pero nunca han reportado heartbeat.',
                $clocksNeverConnected,
                'Validar instalacion o configuracion inicial del dispositivo.'
            ));
        }

        if (($enrollment['without_any_biometric'] ?? 0) > 0) {
            $alerts->push($this->makeAlert(
                'warning',
                'Empleados sin biometria',
                $enrollment['without_any_biometric'].' empleado(s) activos no tienen huella ni Face ID.',
                (int) $enrollment['without_any_biometric'],
                'Priorizar jornadas de enrolamiento para personal sin ningun metodo.'
            ));
        }

        if ($employeesActive > 0 && $attendanceCoverage < 70) {
            $alerts->push($this->makeAlert(
                'warning',
                'Cobertura baja de asistencia',
                'La cobertura de asistencia esta en '.$attendanceCoverage.'% frente al personal activo.',
                $pendingAttendance,
                'Revisar faltantes y validar incidencias operativas del periodo.'
            ));
        }

        if ($syncState && isset($syncState['minutes_since_success']) && $syncState['minutes_since_success'] > 180) {
            $alerts->push($this->makeAlert(
                'info',
                'Sincronizacion antigua',
                'La ultima sincronizacion exitosa de empleados fue hace mas de 3 horas.',
                (int) $syncState['minutes_since_success'],
                'Confirmar la sincronizacion de catalogos y empleados.'
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

    protected function buildConnectivityAlerts(
        int $clocksTotal,
        int $clocksOnline,
        int $clocksOffline,
        int $clocksNeverConnected,
        int $heartbeatStale,
        bool $isBusinessHours
    ): Collection {
        $alerts = collect();

        if ($isBusinessHours) {
            if ($clocksTotal > 0 && $clocksOnline === 0) {
                $alerts->push($this->makeAlert(
                    'critical',
                    'Sin heartbeat reciente',
                    'Ningun reloj biometrico ha reportado dentro de los ultimos '.self::ONLINE_THRESHOLD_MINUTES.' minutos.',
                    $clocksOffline,
                    'Validar energia, red local y heartbeat del sitio.'
                ));
            } elseif ($clocksOffline > 0) {
                $alerts->push($this->makeAlert(
                    'critical',
                    'Relojes sin conexion',
                    $clocksOffline.' reloj(es) no han reportado dentro del umbral operativo.',
                    $clocksOffline,
                    'Revisar conectividad y disponibilidad de los relojes.'
                ));
            }

            if ($heartbeatStale > 0 && $clocksOnline > 0) {
                $alerts->push($this->makeAlert(
                    'warning',
                    'Heartbeat con rezago',
                    $heartbeatStale.' reloj(es) tienen mas de '.self::ONLINE_THRESHOLD_MINUTES.' minutos sin actividad.',
                    $heartbeatStale,
                    'Verificar los equipos con mayor rezago de actividad.'
                ));
            }
        } elseif ($clocksOffline > 0 || $heartbeatStale > 0) {
            $alerts->push($this->makeAlert(
                'info',
                'Conectividad fuera de horario',
                'Fuera de horario operativo: conectividad informativa.',
                max($clocksOffline, $heartbeatStale),
                'Revisar de nuevo durante el horario operativo.'
            ));
        }

        if ($clocksNeverConnected > 0) {
            $alerts->push($this->makeAlert(
                'warning',
                'Relojes nunca conectados',
                $clocksNeverConnected.' reloj(es) estan registrados pero nunca han reportado heartbeat.',
                $clocksNeverConnected,
                'Validar configuracion inicial y asociacion del equipo.'
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
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeAlert(string $level, string $title, string $message, int $metric, ?string $action = null): array
    {
        return [
            'id' => md5($level.'|'.$title.'|'.$message),
            'level' => $level,
            'title' => $title,
            'message' => $message,
            'metric' => $metric,
            'action' => $action,
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
        string $timezone,
        bool $isBusinessHours
    ): array {
        $criticalLocations = $locations->where('status', 'critical')->count();
        $warningLocations = $locations->where('status', 'warning')->count();

        $level = 'normal';

        if (
            (($clocksTotal > 0 && $clocksOnline === 0) && $isBusinessHours)
            || ($clocksOffline > 0 && $isBusinessHours)
            || ($employeesActive > 0 && $attendanceCoverage < 50)
            || $criticalLocations > 0
        ) {
            $level = 'critical';
        } elseif (
            ($clocksWarning > 0 && $isBusinessHours)
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
                ($clocksOffline > 0 && $isBusinessHours) ? $clocksOffline.' reloj(es) sin conexion' : null,
                $employeesActive > 0 ? 'cobertura de asistencia en '.$attendanceCoverage.'%' : null,
                $criticalLocations > 0 ? $criticalLocations.' unidad(es) en estado critico' : null,
            ])),
            'warning' => 'Seguimiento preventivo: '.$this->joinFragments(array_filter([
                ($clocksWarning > 0 && $isBusinessHours) ? $clocksWarning.' reloj(es) requieren atencion' : null,
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
        $clocksAttention = $isBusinessHours
            ? min($clocksOffline + $clocksWarning, $clocksTotal)
            : 0;

        if ($clocksTotal > 0) {
            $bullets[] = $isBusinessHours
                ? $clocksAttention.' de '.$clocksTotal.' relojes requieren atencion.'
                : 'Fuera de horario operativo: conectividad informativa.';
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
            $lastAttendanceLocal = $this->toOperationsDateTime($latestAttendanceAt, $timezone, $this->storageTimezone());
            $bullets[] = 'Ultima asistencia detectada el '.($lastAttendanceLocal?->format('d/m/Y H:i') ?? 'Sin datos').'.';
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

    protected function buildExecutiveSummary(
        int $employeesActive,
        int $attendanceRegistered,
        int $pendingAttendance,
        float $attendanceCoverage,
        int $entriesTotal,
        int $exitsTotal,
        mixed $latestAttendanceAt,
        string $timezone,
        string $storageTimezone,
        array $enrollment,
        array $clocks,
        array $hourlyActivity,
        Collection $alerts,
        bool $isBusinessHours
    ): array {
        $latestAttendanceIso = $this->toOperationsIsoString($latestAttendanceAt, $timezone, $storageTimezone);
        $topAlerts = $alerts
            ->take(3)
            ->map(fn (array $alert): array => [
                'id' => $alert['id'],
                'severity' => $alert['level'],
                'count' => (int) ($alert['metric'] ?? 0),
                'title' => $alert['title'],
                'message' => $alert['message'],
                'action' => $alert['action'] ?? 'Revisar el modulo correspondiente.',
            ])
            ->values()
            ->all();

        return [
            'attendance' => [
                'active_employees' => $employeesActive,
                'attended' => $attendanceRegistered,
                'pending' => $pendingAttendance,
                'coverage_percent' => $attendanceCoverage,
                'entries' => $entriesTotal,
                'exits' => $exitsTotal,
                'last_attendance_at' => $latestAttendanceIso,
            ],
            'enrolment' => [
                'active_employees' => (int) ($enrollment['employees_active'] ?? 0),
                'with_any_biometric' => (int) ($enrollment['with_any_biometric'] ?? 0),
                'with_fingerprint' => (int) ($enrollment['with_fingerprint'] ?? 0),
                'with_face' => (int) ($enrollment['with_face'] ?? 0),
                'with_both_biometrics' => (int) ($enrollment['with_both_biometrics'] ?? 0),
                'without_fingerprint' => (int) ($enrollment['without_fingerprint'] ?? 0),
                'without_face' => (int) ($enrollment['without_face'] ?? 0),
                'without_any_biometric' => (int) ($enrollment['without_any_biometric'] ?? 0),
                'coverage_percent' => (float) ($enrollment['coverage_percent'] ?? $enrollment['coverage_percentage'] ?? 0),
            ],
            'clocks' => [
                'total' => (int) ($clocks['total'] ?? 0),
                'online' => (int) ($clocks['online'] ?? 0),
                'offline' => (int) ($clocks['offline'] ?? 0),
                'stale' => (int) ($clocks['heartbeat_stale'] ?? 0),
                'operational_status' => $clocks['operational_status'] ?? ($clocks['status'] ?? 'nodata'),
                'is_business_hours' => $isBusinessHours,
                'operational_note' => $clocks['operational_note'] ?? ($clocks['status_reason'] ?? null),
            ],
            'compact_charts' => [
                'attendance_donut' => [
                    'attended' => $attendanceRegistered,
                    'pending' => $pendingAttendance,
                    'coverage_percent' => $attendanceCoverage,
                ],
                'enrolment_bar' => [
                    'with_any_biometric' => (int) ($enrollment['with_any_biometric'] ?? 0),
                    'without_any_biometric' => (int) ($enrollment['without_any_biometric'] ?? 0),
                    'with_fingerprint' => (int) ($enrollment['with_fingerprint'] ?? 0),
                    'with_face' => (int) ($enrollment['with_face'] ?? 0),
                    'coverage_percent' => (float) ($enrollment['coverage_percent'] ?? $enrollment['coverage_percentage'] ?? 0),
                ],
                'hourly_activity' => $hourlyActivity,
                'clocks_status' => [
                    'online' => (int) ($clocks['online'] ?? 0),
                    'offline' => (int) ($clocks['offline'] ?? 0),
                    'stale' => (int) ($clocks['heartbeat_stale'] ?? 0),
                    'total' => (int) ($clocks['total'] ?? 0),
                ],
            ],
            'alerts' => $topAlerts,
        ];
    }

    protected function isBusinessHours(string $timezone): bool
    {
        $hour = (int) now($timezone)->format('G');

        return $hour >= 7 && $hour < 20;
    }

    protected function joinFragments(array $parts): string
    {
        $parts = array_values(array_filter($parts, fn ($value) => $value !== null && $value !== ''));

        if ($parts === []) {
            return 'sin incidencias relevantes';
        }

        if (count($parts) === 1) {
            return (string) $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' y '.$last;
    }

    protected function resolveLatestSyncState(string $timezone, string $storageTimezone): ?array
    {
        try {
            $state = EmployeeSyncState::query()
                ->orderByDesc('last_success_at')
                ->orderByDesc('last_synced_at')
                ->first();

            if (! $state) {
                return null;
            }

            $lastSuccessAt = $this->toOperationsDateTime(
                $state->getRawOriginal('last_success_at') ?: $state->getRawOriginal('last_synced_at'),
                $timezone,
                $storageTimezone
            );
            $lastSyncedAt = $this->toOperationsDateTime(
                $state->getRawOriginal('last_synced_at'),
                $timezone,
                $storageTimezone
            );

            return [
                'source' => $state->source,
                'last_sync_status' => $state->last_sync_status,
                'last_synced_at' => $lastSyncedAt?->toIso8601String(),
                'last_success_at' => optional($lastSuccessAt)?->toIso8601String(),
                'minutes_since_success' => $lastSuccessAt ? $lastSuccessAt->diffInMinutes(now($timezone)) : null,
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
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get()
            ->map(function (AttendanceLog $log) use ($timezone): array {
                $occurredAt = $this->toOperationsDateTime(
                    $log->getRawOriginal('log_date'),
                    $timezone,
                    $this->storageTimezone()
                );

                return [
                    'id' => (int) $log->id,
                    'employee_name' => $log->employee?->full_name ?? $log->employee?->name ?? 'Empleado sin nombre',
                    'unit_name' => $log->location?->name ?? 'Sin unidad',
                    'clock_name' => $log->clock?->clock_name ?? ($log->clock?->serial_number ? 'Reloj '.$log->clock->serial_number : 'Sin reloj'),
                    'event_type' => $this->formatLogType((int) $log->log_type),
                    'log_type' => (int) $log->log_type,
                    'method' => $this->resolveAttendanceMethod($log),
                    'source' => $log->source ?? 'sync',
                    'source_label' => $this->resolveAttendanceSource($log),
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
            default => 'No clasificado',
        };
    }

    protected function resolveAttendanceSource(AttendanceLog $log): string
    {
        $normalized = strtolower(trim((string) ($log->source ?? '')));

        return match ($normalized) {
            'api' => 'API',
            'manual' => 'Manual',
            'sync' => 'Sync',
            'device', 'clock', 'reloj' => 'Reloj',
            '', 'unknown', 'desconocido' => 'Sin fuente',
            default => ucfirst($normalized),
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

        $lastHeartbeatAt = $this->toOperationsDateTime(
            $clock->getRawOriginal('last_heartbeat_at'),
            $this->operationsTimezone(),
            $this->storageTimezone()
        );

        return [
            'id' => (int) $clock->id,
            'name' => $clock->clock_name ?: 'Reloj #'.$clock->id,
            'serial_number' => $clock->serial_number,
            'location_name' => $clock->location?->name,
            'last_heartbeat_at' => $lastHeartbeatAt?->toIso8601String(),
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

    protected function hourBucketExpression(string $column, string $timezone, string $storageTimezone): string
    {
        $offsetMinutes = $this->timezoneOffsetMinutes($timezone, $storageTimezone);
        $sqliteModifier = $this->sqliteOffsetModifier($offsetMinutes);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%H', datetime({$column}, '{$sqliteModifier}'))",
            default => "LPAD(HOUR(TIMESTAMPADD(MINUTE, {$offsetMinutes}, {$column})), 2, '0')",
        };
    }

    protected function dateBucketExpression(string $column, string $timezone, string $storageTimezone): string
    {
        $offsetMinutes = $this->timezoneOffsetMinutes($timezone, $storageTimezone);
        $sqliteModifier = $this->sqliteOffsetModifier($offsetMinutes);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', datetime({$column}, '{$sqliteModifier}'))",
            default => "DATE(TIMESTAMPADD(MINUTE, {$offsetMinutes}, {$column}))",
        };
    }

    protected function operationsTimezone(): string
    {
        return (string) config('operations.timezone', 'America/Mexico_City');
    }

    protected function operationsTimezoneLabel(): string
    {
        return (string) config('operations.timezone_label', 'Hora centro de Mexico');
    }

    protected function storageTimezone(): string
    {
        return (string) config('operations.storage_timezone', 'UTC');
    }

    protected function toStorageTimezone(Carbon $date, string $storageTimezone): Carbon
    {
        return $date->copy()->setTimezone($storageTimezone);
    }

    protected function toOperationsIsoString(mixed $value, string $timezone, string $storageTimezone): ?string
    {
        return $this->toOperationsDateTime($value, $timezone, $storageTimezone)?->toIso8601String();
    }

    protected function toOperationsDateTime(mixed $value, string $timezone, string $storageTimezone): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return Carbon::parse($value->format('Y-m-d H:i:s'), $storageTimezone)->setTimezone($timezone);
        }

        return Carbon::parse((string) $value, $storageTimezone)->setTimezone($timezone);
    }

    protected function timezoneOffsetMinutes(string $timezone, string $storageTimezone): int
    {
        $reference = now('UTC');
        $storageOffset = $reference->copy()->setTimezone($storageTimezone)->utcOffset();
        $operationsOffset = $reference->copy()->setTimezone($timezone)->utcOffset();

        return $operationsOffset - $storageOffset;
    }

    protected function timezoneOffsetString(string $timezone): string
    {
        return now($timezone)->format('P');
    }

    protected function sqliteOffsetModifier(int $offsetMinutes): string
    {
        $sign = $offsetMinutes >= 0 ? '+' : '-';
        $absolute = abs($offsetMinutes);
        $hours = str_pad((string) intdiv($absolute, 60), 2, '0', STR_PAD_LEFT);
        $minutes = str_pad((string) ($absolute % 60), 2, '0', STR_PAD_LEFT);

        return "{$sign}{$hours}:{$minutes}";
    }
}
