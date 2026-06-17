<?php

namespace App\Services\Dashboard;

use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Employee;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorporateRecruitmentDashboardService
{
    public const RANGE_TODAY = 'today';
    public const RANGE_YESTERDAY = 'yesterday';
    public const RANGE_CURRENT_WEEK = 'current_week';
    public const RANGE_FORTNIGHT = 'fortnight';
    public const RANGE_CUSTOM = 'custom';
    public const DETAIL_EXPORT_LIMIT = 5000;
    public const MAX_ALERTS = 5;

    /**
     * @var array<int, string>
     */
    private const TARGET_CODES = ['87', '171'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildCatalog(array $filters = []): array
    {
        $locations = $this->resolveScopedLocations();
        $normalized = $this->normalizeFilters($filters, $locations);
        $clockOptions = $this->buildClockCatalog($locations);

        return [
            'filters' => $normalized['filters'],
            'locations' => $this->transformLocationOptions($locations),
            'clocks' => $clockOptions,
            'timezone' => [
                'name' => $this->operationsTimezone(),
                'label' => 'Hora centro de Mexico',
                'note' => 'Horarios mostrados en hora centro de Mexico.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $locations = $this->resolveScopedLocations();
        $normalized = $this->normalizeFilters($filters, $locations);
        $selectedLocations = $normalized['selected_locations'];
        $selectedLocationIds = $selectedLocations->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selectedClockId = $normalized['clock_id'];
        $timezone = $this->operationsTimezone();
        $storageTimezone = $this->storageTimezone();
        $onlineThreshold = Clock::heartbeatOnlineThreshold();
        $isBusinessHours = $this->isBusinessHours($timezone);

        if ($selectedLocationIds === []) {
            return [
                'filters' => $normalized['filters'],
                'locations' => [],
                'global' => $this->emptyGlobalSummary(),
                'hourly_activity' => $this->emptyHourlyActivity(),
                'clock_ranking' => [],
                'alerts' => [],
                'charts' => $this->emptyCharts(),
                'timezone' => [
                    'name' => $timezone,
                    'label' => 'Hora centro de Mexico',
                    'note' => 'Horarios mostrados en hora centro de Mexico.',
                ],
                'meta' => $this->buildMeta(
                    $normalized['filters'],
                    $normalized['from_local'],
                    $normalized['to_local'],
                    null
                ),
                'empty' => true,
                'message' => 'No se encontraron unidades configuradas para Corporativo y Reclutamiento.',
            ];
        }

        $attendanceBase = $this->attendanceQuery(
            from: $normalized['from_storage'],
            to: $normalized['to_storage'],
            locationIds: $selectedLocationIds,
            clockId: $selectedClockId
        );
        $clocks = $this->clockCollection($selectedLocationIds, $selectedClockId);
        $attendanceByLocation = $this->attendanceSummaryByLocation(clone $attendanceBase);
        $attendanceDistinctByLocation = $this->attendanceDistinctEmployeesByLocation(clone $attendanceBase);
        $employeeCountsByLocation = $this->activeEmployeesByLocation($selectedLocations);
        $globalAttended = (clone $attendanceBase)
            ->whereNotNull('employee_id')
            ->distinct('employee_id')
            ->count('employee_id');
        $globalChecks = (clone $attendanceBase)->count();
        $globalFirstCheckAt = (clone $attendanceBase)->min('log_date');
        $globalLastCheckAt = (clone $attendanceBase)->max('log_date');

        $locationPayload = $selectedLocations
            ->map(function (Location $location) use (
                $attendanceByLocation,
                $attendanceDistinctByLocation,
                $employeeCountsByLocation,
                $clocks,
                $onlineThreshold,
                $timezone,
                $storageTimezone,
                $isBusinessHours
            ): array {
                $locationId = (int) $location->id;
                $attendanceRow = $attendanceByLocation->get($locationId, []);
                $attended = (int) ($attendanceDistinctByLocation[$locationId] ?? 0);
                $activeEmployees = (int) ($employeeCountsByLocation[$locationId] ?? 0);
                $pending = max($activeEmployees - $attended, 0);
                $locationClocks = $clocks->where('location_id', $locationId)->values();
                $clockSummary = $this->summarizeClocks($locationClocks, $onlineThreshold, $isBusinessHours, $timezone, $storageTimezone);

                return [
                    'id' => $locationId,
                    'code' => (string) ($location->code ?? ''),
                    'fortia_location_id' => $location->fortia_location_id,
                    'name' => $location->name,
                    'summary' => [
                        'active_employees' => $activeEmployees,
                        'attended' => $attended,
                        'pending' => $pending,
                        'coverage_percent' => $this->percentage($attended, $activeEmployees),
                        'total_checks' => (int) ($attendanceRow['total_checks'] ?? 0),
                        'entries' => (int) ($attendanceRow['entries'] ?? 0),
                        'exits' => (int) ($attendanceRow['exits'] ?? 0),
                        'unknown' => (int) ($attendanceRow['unknown'] ?? 0),
                        'first_check_at' => $this->toOperationsIsoString($attendanceRow['first_check_at'] ?? null, $timezone, $storageTimezone),
                        'last_check_at' => $this->toOperationsIsoString($attendanceRow['last_check_at'] ?? null, $timezone, $storageTimezone),
                    ],
                    'clocks' => $clockSummary,
                ];
            })
            ->values();

        $globalActiveEmployees = (int) array_sum($employeeCountsByLocation);
        $globalPending = max($globalActiveEmployees - $globalAttended, 0);
        $globalClockSummary = $this->summarizeClocks($clocks, $onlineThreshold, $isBusinessHours, $timezone, $storageTimezone);
        $hourlyActivity = $this->buildHourlyActivity(clone $attendanceBase, $timezone, $storageTimezone);
        $clockRanking = $this->buildClockRanking($clocks, clone $attendanceBase, $locationPayload, $timezone, $storageTimezone, $onlineThreshold);
        $alerts = $this->buildAlerts($locationPayload, $clockRanking, $globalClockSummary, $isBusinessHours, $timezone, $storageTimezone);
        $meta = $this->buildMeta($normalized['filters'], $normalized['from_local'], $normalized['to_local'], $globalLastCheckAt);

        return [
            'filters' => $normalized['filters'],
            'locations' => $locationPayload->all(),
            'global' => [
                'active_employees' => $globalActiveEmployees,
                'attended' => $globalAttended,
                'pending' => $globalPending,
                'coverage_percent' => $this->percentage($globalAttended, $globalActiveEmployees),
                'total_checks' => $globalChecks,
                'entries' => (int) $locationPayload->sum(fn (array $row) => $row['summary']['entries']),
                'exits' => (int) $locationPayload->sum(fn (array $row) => $row['summary']['exits']),
                'unknown' => (int) $locationPayload->sum(fn (array $row) => $row['summary']['unknown']),
                'first_check_at' => $this->toOperationsIsoString($globalFirstCheckAt, $timezone, $storageTimezone),
                'last_check_at' => $this->toOperationsIsoString($globalLastCheckAt, $timezone, $storageTimezone),
                'clocks_total' => $globalClockSummary['total'],
                'clocks_active' => $globalClockSummary['active'],
                'clocks_online' => $globalClockSummary['online'],
                'clocks_offline' => $globalClockSummary['offline'],
                'clocks_stale' => $globalClockSummary['stale'],
                'last_heartbeat_at' => $globalClockSummary['last_heartbeat_at'],
                'operational_status' => $globalClockSummary['operational_status'],
                'operational_label' => $globalClockSummary['operational_label'],
            ],
            'hourly_activity' => $hourlyActivity,
            'clock_ranking' => $clockRanking,
            'alerts' => $alerts,
            'charts' => $this->buildCharts($locationPayload, $hourlyActivity, $globalClockSummary),
            'timezone' => [
                'name' => $timezone,
                'label' => 'Hora centro de Mexico',
                'note' => 'Horarios mostrados en hora centro de Mexico.',
            ],
            'meta' => $meta,
            'empty' => $globalActiveEmployees === 0 && $globalChecks === 0 && $globalClockSummary['total'] === 0,
            'message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildExportData(array $filters = []): array
    {
        $payload = $this->build($filters);
        $detailRows = $this->buildDetailRows($payload['filters']);
        $generatedAt = now($this->operationsTimezone());

        return [
            'filters' => $payload['filters'],
            'generated_at' => $generatedAt->toIso8601String(),
            'sheets' => [
                'global' => $this->buildGlobalSheetRows($payload, $generatedAt),
                'locations' => $this->buildLocationSheetRows($payload),
                'ranking' => $this->buildRankingSheetRows($payload),
                'detail' => $detailRows,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function buildExportFilename(array $filters, string $generatedAt): string
    {
        $range = $filters['range'] ?? self::RANGE_TODAY;
        $date = Carbon::parse($generatedAt)->setTimezone($this->operationsTimezone())->format('Ymd_His');

        return 'dashboard_corporativo_reclutamiento_'.$range.'_'.$date;
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    public function streamCsvExport(array $exportData, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($exportData): void {
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF).chr(0xBB).chr(0xBF));

            foreach ([
                $exportData['sheets']['global'] ?? [],
                [],
                $exportData['sheets']['locations'] ?? [],
                [],
                $exportData['sheets']['ranking'] ?? [],
                [],
                $exportData['sheets']['detail'] ?? [],
            ] as $sheetRows) {
                if ($sheetRows === []) {
                    fputcsv($output, []);
                    continue;
                }

                foreach ($sheetRows as $row) {
                    fputcsv($output, $row);
                }
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Collection<int, Location>
     */
    protected function resolveScopedLocations(): Collection
    {
        $resolved = collect();
        $missing = collect(self::TARGET_CODES);

        if (Schema::hasColumn('locations', 'code')) {
            $byCode = Location::query()
                ->whereIn('code', self::TARGET_CODES)
                ->orderBy('name')
                ->get()
                ->groupBy(fn (Location $location) => (string) $location->code)
                ->map(fn (Collection $rows) => $rows->first());

            foreach (self::TARGET_CODES as $code) {
                if ($byCode->has($code)) {
                    $resolved->push($byCode->get($code));
                    $missing = $missing->reject(fn (string $value) => $value === $code)->values();
                }
            }
        }

        if ($missing->isNotEmpty() && Schema::hasColumn('locations', 'fortia_location_id')) {
            $byFortia = Location::query()
                ->whereIn('fortia_location_id', $missing->map(fn (string $value) => (int) $value)->all())
                ->orderBy('name')
                ->get()
                ->groupBy(fn (Location $location) => (string) $location->fortia_location_id)
                ->map(fn (Collection $rows) => $rows->first());

            foreach ($missing as $code) {
                if ($byFortia->has($code)) {
                    $resolved->push($byFortia->get($code));
                }
            }
        }

        $resolved = $resolved
            ->filter()
            ->unique(fn (Location $location) => (int) $location->id)
            ->sortBy(fn (Location $location) => array_search((string) ($location->code ?: $location->fortia_location_id), self::TARGET_CODES, true) ?: 0)
            ->values();

        $foundCodes = $resolved
            ->flatMap(fn (Location $location) => [
                $location->code !== null ? (string) $location->code : null,
                $location->fortia_location_id !== null ? (string) $location->fortia_location_id : null,
            ])
            ->filter()
            ->unique();

        $notFound = collect(self::TARGET_CODES)
            ->reject(fn (string $code) => $foundCodes->contains($code))
            ->values();

        if ($notFound->isNotEmpty()) {
            Log::warning('dashboard.corporate_recruitment.locations_missing', [
                'requested_codes' => self::TARGET_CODES,
                'missing_codes' => $notFound->all(),
                'resolved_location_ids' => $resolved->pluck('id')->all(),
            ]);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, Location>  $locations
     * @return array<string, mixed>
     */
    protected function normalizeFilters(array $filters, Collection $locations): array
    {
        $timezone = $this->operationsTimezone();
        $storageTimezone = $this->storageTimezone();
        $range = (string) ($filters['range'] ?? self::RANGE_TODAY);
        $allowedLocationIds = $locations->pluck('id')->map(fn ($id) => (int) $id)->all();
        $unitId = isset($filters['unit_id']) && in_array((int) $filters['unit_id'], $allowedLocationIds, true)
            ? (int) $filters['unit_id']
            : null;

        [$fromLocal, $toLocal] = $this->resolveRange(
            $range,
            $filters['from_date'] ?? null,
            $filters['to_date'] ?? null,
            $timezone
        );

        $selectedLocations = $unitId
            ? $locations->where('id', $unitId)->values()
            : $locations->values();

        $selectedLocationIds = $selectedLocations->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allowedClockIds = Clock::query()
            ->whereIn('location_id', $selectedLocationIds === [] ? [-1] : $selectedLocationIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $clockId = isset($filters['clock_id']) && in_array((int) $filters['clock_id'], $allowedClockIds, true)
            ? (int) $filters['clock_id']
            : null;

        return [
            'filters' => [
                'range' => $range,
                'from_date' => $fromLocal->format('Y-m-d'),
                'to_date' => $toLocal->format('Y-m-d'),
                'unit_id' => $unitId,
                'clock_id' => $clockId,
                'format' => $filters['format'] ?? 'xlsx',
            ],
            'unit_id' => $unitId,
            'clock_id' => $clockId,
            'selected_locations' => $selectedLocations,
            'from_local' => $fromLocal,
            'to_local' => $toLocal,
            'from_storage' => $fromLocal->copy()->setTimezone($storageTimezone),
            'to_storage' => $toLocal->copy()->setTimezone($storageTimezone),
        ];
    }

    protected function attendanceQuery(Carbon $from, Carbon $to, array $locationIds, ?int $clockId = null): Builder
    {
        return AttendanceLog::query()
            ->whereBetween('log_date', [$from, $to])
            ->whereIn('location_id', $locationIds)
            ->when($clockId, fn (Builder $query) => $query->where('device_id', $clockId))
            ->where(function (Builder $query): void {
                $query->whereNull('attendance_status')
                    ->orWhere('attendance_status', '!=', 'anulada');
            });
    }

    protected function clockCollection(array $locationIds, ?int $clockId = null): Collection
    {
        return Clock::query()
            ->with('location:id,name,code')
            ->whereIn('location_id', $locationIds)
            ->when($clockId, fn (Builder $query) => $query->whereKey($clockId))
            ->get([
                'id',
                'company_id',
                'location_id',
                'clock_name',
                'serial_number',
                'status',
                'last_heartbeat_at',
                'last_status_message',
                'monitoring_status',
                'program_status',
            ]);
    }

    protected function attendanceSummaryByLocation(Builder $attendanceBase): Collection
    {
        $entryTypes = $this->entryTypes();
        $exitTypes = $this->exitTypes();
        $entrySql = 'SUM(CASE WHEN log_type IN ('.implode(',', $entryTypes).') THEN 1 ELSE 0 END)';
        $exitSql = 'SUM(CASE WHEN log_type IN ('.implode(',', $exitTypes).') THEN 1 ELSE 0 END)';
        $knownTypes = array_values(array_unique(array_merge($entryTypes, $exitTypes)));
        $unknownSql = 'SUM(CASE WHEN log_type NOT IN ('.implode(',', $knownTypes).') THEN 1 ELSE 0 END)';

        return $attendanceBase
            ->select('location_id')
            ->selectRaw('COUNT(*) as total_checks')
            ->selectRaw($entrySql.' as entries')
            ->selectRaw($exitSql.' as exits')
            ->selectRaw($unknownSql.' as unknown')
            ->selectRaw('MIN(log_date) as first_check_at')
            ->selectRaw('MAX(log_date) as last_check_at')
            ->groupBy('location_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->location_id => [
                    'total_checks' => (int) $row->total_checks,
                    'entries' => (int) $row->entries,
                    'exits' => (int) $row->exits,
                    'unknown' => (int) $row->unknown,
                    'first_check_at' => $row->first_check_at,
                    'last_check_at' => $row->last_check_at,
                ],
            ]);
    }

    protected function attendanceDistinctEmployeesByLocation(Builder $attendanceBase): Collection
    {
        return $attendanceBase
            ->select('location_id')
            ->selectRaw('COUNT(DISTINCT employee_id) as attended')
            ->whereNotNull('employee_id')
            ->groupBy('location_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->location_id => (int) $row->attended]);
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @return array<int, int>
     */
    protected function activeEmployeesByLocation(Collection $locations): array
    {
        $candidateMap = [];

        foreach ($locations as $location) {
            $candidateMap[(int) $location->id] = $this->employeeLocationCandidates($location);
        }

        $allCandidates = collect($candidateMap)->flatten(1)->unique()->values()->all();

        if ($allCandidates === []) {
            return [];
        }

        $rows = Employee::query()
            ->select('base_location_id', DB::raw('COUNT(*) as total'))
            ->whereIn('status', ['A', 'ACTIVE', 'active'])
            ->whereIn('base_location_id', $allCandidates)
            ->groupBy('base_location_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->base_location_id => (int) $row->total]);

        $counts = [];

        foreach ($candidateMap as $locationId => $candidates) {
            $counts[$locationId] = collect($candidates)
                ->sum(fn ($candidate) => (int) ($rows[(string) $candidate] ?? 0));
        }

        return $counts;
    }

    /**
     * @return array<int, int|string>
     */
    protected function employeeLocationCandidates(Location $location): array
    {
        return collect([
            $location->id,
            $location->fortia_location_id,
            $location->code,
        ])->filter(fn ($value) => $value !== null && $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function summarizeClocks(
        Collection $clocks,
        Carbon $onlineThreshold,
        bool $isBusinessHours,
        string $timezone,
        string $storageTimezone
    ): array {
        $total = $clocks->count();
        $active = $clocks->where('status', 1)->count();
        $online = $clocks
            ->where('status', 1)
            ->filter(fn (Clock $clock) => $clock->last_heartbeat_at !== null && $clock->last_heartbeat_at->greaterThanOrEqualTo($onlineThreshold))
            ->count();
        $offline = $clocks
            ->where('status', 1)
            ->filter(fn (Clock $clock) => $clock->last_heartbeat_at === null)
            ->count();
        $stale = $clocks
            ->where('status', 1)
            ->filter(fn (Clock $clock) => $clock->last_heartbeat_at !== null && $clock->last_heartbeat_at->lt($onlineThreshold))
            ->count();
        $latestClock = $clocks
            ->filter(fn (Clock $clock) => $clock->last_heartbeat_at !== null)
            ->sortByDesc(fn (Clock $clock) => optional($clock->last_heartbeat_at)->getTimestamp() ?? 0)
            ->first();
        $operationalStatus = $this->resolveOperationalStatus($total, $online, $offline + $stale, $isBusinessHours);

        return [
            'total' => $total,
            'active' => $active,
            'online' => $online,
            'offline' => $offline,
            'stale' => $stale,
            'last_heartbeat_at' => $this->toOperationsIsoString($latestClock?->last_heartbeat_at, $timezone, $storageTimezone),
            'last_status_message' => $latestClock?->last_status_message,
            'operational_status' => $operationalStatus['status'],
            'operational_label' => $operationalStatus['label'],
        ];
    }

    protected function resolveOperationalStatus(int $total, int $online, int $notOnline, bool $isBusinessHours): array
    {
        if ($total === 0) {
            return ['status' => 'nodata', 'label' => 'Sin relojes'];
        }

        if (! $isBusinessHours) {
            return ['status' => 'info', 'label' => 'Fuera de horario'];
        }

        if ($online === 0 || $notOnline >= max(1, (int) ceil($total / 2))) {
            return ['status' => 'critical', 'label' => 'Alerta critica'];
        }

        if ($notOnline > 0) {
            return ['status' => 'warning', 'label' => 'Atencion preventiva'];
        }

        return ['status' => 'normal', 'label' => 'Operacion normal'];
    }

    protected function buildHourlyActivity(Builder $attendanceBase, string $timezone, string $storageTimezone): array
    {
        $hourExpression = $this->hourBucketExpression('log_date', $timezone, $storageTimezone);
        $entryTypes = $this->entryTypes();
        $exitTypes = $this->exitTypes();
        $knownTypes = array_values(array_unique(array_merge($entryTypes, $exitTypes)));

        $rows = $attendanceBase
            ->selectRaw($hourExpression.' as hour_key')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN log_type IN ('.implode(',', $entryTypes).') THEN 1 ELSE 0 END) as entries')
            ->selectRaw('SUM(CASE WHEN log_type IN ('.implode(',', $exitTypes).') THEN 1 ELSE 0 END) as exits')
            ->selectRaw('SUM(CASE WHEN log_type NOT IN ('.implode(',', $knownTypes).') THEN 1 ELSE 0 END) as unknown')
            ->groupBy('hour_key')
            ->orderBy('hour_key')
            ->get()
            ->mapWithKeys(fn ($row) => [str_pad((string) $row->hour_key, 2, '0', STR_PAD_LEFT) => $row]);

        $hours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $row = $rows->get($key);

            $hours[] = [
                'hour' => $key.':00',
                'total' => (int) ($row->total ?? 0),
                'entries' => (int) ($row->entries ?? 0),
                'exits' => (int) ($row->exits ?? 0),
                'unknown' => (int) ($row->unknown ?? 0),
            ];
        }

        return $hours;
    }

    protected function buildClockRanking(
        Collection $clocks,
        Builder $attendanceBase,
        Collection $locationPayload,
        string $timezone,
        string $storageTimezone,
        Carbon $onlineThreshold
    ): array {
        $attendanceByClock = $attendanceBase
            ->select('device_id')
            ->selectRaw('COUNT(*) as total_checks')
            ->selectRaw('MAX(log_date) as last_check_at')
            ->groupBy('device_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->device_id);
        $locationMap = $locationPayload->keyBy('id');

        return $clocks
            ->map(function (Clock $clock) use ($attendanceByClock, $locationMap, $timezone, $storageTimezone, $onlineThreshold): array {
                $clockAttendance = $attendanceByClock->get((int) $clock->id);
                $isOnline = (int) $clock->status === 1
                    && $clock->last_heartbeat_at !== null
                    && $clock->last_heartbeat_at->greaterThanOrEqualTo($onlineThreshold);
                $heartbeatStatus = $isOnline
                    ? 'online'
                    : ($clock->last_heartbeat_at ? 'stale' : 'offline');

                return [
                    'id' => (int) $clock->id,
                    'location_id' => (int) $clock->location_id,
                    'location_name' => $locationMap[(int) $clock->location_id]['name'] ?? $clock->location?->name ?? 'Sin unidad',
                    'clock_name' => $clock->clock_name ?: 'Reloj #'.$clock->id,
                    'serial_number' => $clock->serial_number,
                    'status' => $heartbeatStatus,
                    'status_label' => match ($heartbeatStatus) {
                        'online' => 'En linea',
                        'stale' => 'Heartbeat vencido',
                        default => 'Sin conexion',
                    },
                    'online' => $isOnline,
                    'last_heartbeat_at' => $this->toOperationsIsoString($clock->last_heartbeat_at, $timezone, $storageTimezone),
                    'last_status_message' => $clock->last_status_message,
                    'total_checks' => (int) ($clockAttendance->total_checks ?? 0),
                    'last_check_at' => $this->toOperationsIsoString($clockAttendance->last_check_at ?? null, $timezone, $storageTimezone),
                    'clock_catalog_url' => route('clocks.index'),
                ];
            })
            ->sortByDesc(fn (array $row) => [$row['total_checks'], $row['last_check_at'] ?? ''])
            ->values()
            ->all();
    }

    protected function buildAlerts(
        Collection $locationPayload,
        array $clockRanking,
        array $globalClockSummary,
        bool $isBusinessHours,
        string $timezone,
        string $storageTimezone
    ): array {
        $alerts = collect();

        foreach ($locationPayload as $location) {
            $summary = $location['summary'];
            $clocks = $location['clocks'];

            if ($isBusinessHours && $summary['active_employees'] > 0 && $summary['total_checks'] === 0) {
                $alerts->push($this->makeAlert(
                    'critical',
                    'Unidad sin checadas',
                    $location['name'].' no registra checadas en horario operativo.',
                    100
                ));
            }

            if ($isBusinessHours && $clocks['offline'] > 0) {
                $alerts->push($this->makeAlert(
                    'critical',
                    'Reloj offline en horario operativo',
                    $location['name'].' tiene '.$clocks['offline'].' reloj(es) sin conexion.',
                    90 + $clocks['offline']
                ));
            }

            if ($clocks['stale'] > 0) {
                $alerts->push($this->makeAlert(
                    $isBusinessHours ? 'warning' : 'info',
                    'Reloj con heartbeat vencido',
                    $location['name'].' tiene '.$clocks['stale'].' reloj(es) con heartbeat vencido.',
                    70 + $clocks['stale']
                ));
            }

            if ($summary['coverage_percent'] < 70 && $summary['active_employees'] > 0) {
                $alerts->push($this->makeAlert(
                    'warning',
                    'Cobertura baja',
                    $location['name'].' tiene una cobertura de asistencia de '.$summary['coverage_percent'].'%.',
                    60 + $summary['pending']
                ));
            }

            if ($summary['pending'] > 0) {
                $alerts->push($this->makeAlert(
                    'warning',
                    'Empleados pendientes',
                    $location['name'].' mantiene '.$summary['pending'].' empleado(s) pendientes de checada.',
                    40 + $summary['pending']
                ));
            }

            if ($summary['last_check_at']) {
                $lastCheck = $this->toOperationsDateTime($summary['last_check_at'], $timezone, $storageTimezone);

                if ($isBusinessHours && $lastCheck && $lastCheck->lt(now($timezone)->subHours(2))) {
                    $alerts->push($this->makeAlert(
                        'warning',
                        'Ultima checada antigua',
                        $location['name'].' no registra movimiento reciente desde '.$lastCheck->format('d/m/Y H:i').'.',
                        50
                    ));
                }
            }
        }

        if (! $isBusinessHours && ($globalClockSummary['offline'] > 0 || $globalClockSummary['stale'] > 0)) {
            $alerts->push($this->makeAlert(
                'info',
                'Conectividad fuera de horario',
                'Fuera de horario operativo: la conectividad de relojes se reporta de forma informativa.',
                30
            ));
        }

        if ($clockRanking !== [] && collect($clockRanking)->every(fn (array $row) => $row['total_checks'] === 0)) {
            $alerts->push($this->makeAlert(
                'warning',
                'Sin actividad de relojes',
                'Ningun reloj registra actividad para el filtro seleccionado.',
                35
            ));
        }

        return $alerts
            ->sort(function (array $left, array $right): int {
                $priority = ['critical' => 0, 'warning' => 1, 'info' => 2];
                $leftPriority = $priority[$left['level']] ?? 99;
                $rightPriority = $priority[$right['level']] ?? 99;

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                return ($right['score'] ?? 0) <=> ($left['score'] ?? 0);
            })
            ->unique(fn (array $alert) => $alert['title'].'|'.$alert['message'])
            ->take(self::MAX_ALERTS)
            ->values()
            ->all();
    }

    protected function buildCharts(Collection $locationPayload, array $hourlyActivity, array $globalClockSummary): array
    {
        $labels = $locationPayload->map(fn (array $location) => $location['name'])->all();

        return [
            'attendance_by_location' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Asistieron',
                        'data' => $locationPayload->map(fn (array $location) => $location['summary']['attended'])->all(),
                        'backgroundColor' => '#0f766e',
                        'borderRadius' => 12,
                    ],
                    [
                        'label' => 'Pendientes',
                        'data' => $locationPayload->map(fn (array $location) => $location['summary']['pending'])->all(),
                        'backgroundColor' => '#f59e0b',
                        'borderRadius' => 12,
                    ],
                ],
            ],
            'checks_by_hour' => [
                'labels' => collect($hourlyActivity)->pluck('hour')->all(),
                'datasets' => [
                    [
                        'label' => 'Entradas',
                        'data' => collect($hourlyActivity)->pluck('entries')->all(),
                        'borderColor' => '#0f766e',
                        'backgroundColor' => 'rgba(15,118,110,0.18)',
                        'fill' => true,
                        'tension' => 0.35,
                    ],
                    [
                        'label' => 'Salidas',
                        'data' => collect($hourlyActivity)->pluck('exits')->all(),
                        'borderColor' => '#dc2626',
                        'backgroundColor' => 'rgba(220,38,38,0.14)',
                        'fill' => true,
                        'tension' => 0.35,
                    ],
                    [
                        'label' => 'Total',
                        'data' => collect($hourlyActivity)->pluck('total')->all(),
                        'borderColor' => '#1d4ed8',
                        'backgroundColor' => 'rgba(29,78,216,0.12)',
                        'fill' => false,
                        'tension' => 0.3,
                    ],
                ],
            ],
            'clock_status' => [
                'labels' => ['En linea', 'Sin conexion', 'Heartbeat vencido'],
                'datasets' => [[
                    'label' => 'Relojes',
                    'data' => [
                        $globalClockSummary['online'],
                        $globalClockSummary['offline'],
                        $globalClockSummary['stale'],
                    ],
                    'backgroundColor' => ['#0f766e', '#dc2626', '#f59e0b'],
                ]],
            ],
            'entries_vs_exits' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Entradas',
                        'data' => $locationPayload->map(fn (array $location) => $location['summary']['entries'])->all(),
                        'backgroundColor' => '#164e63',
                        'borderRadius' => 12,
                    ],
                    [
                        'label' => 'Salidas',
                        'data' => $locationPayload->map(fn (array $location) => $location['summary']['exits'])->all(),
                        'backgroundColor' => '#be123c',
                        'borderRadius' => 12,
                    ],
                    [
                        'label' => 'Desconocidas',
                        'data' => $locationPayload->map(fn (array $location) => $location['summary']['unknown'])->all(),
                        'backgroundColor' => '#64748b',
                        'borderRadius' => 12,
                    ],
                ],
            ],
        ];
    }

    protected function buildMeta(array $filters, Carbon $fromLocal, Carbon $toLocal, mixed $lastCheckAt): array
    {
        return [
            'range' => $filters['range'],
            'from' => $fromLocal->toIso8601String(),
            'to' => $toLocal->toIso8601String(),
            'generated_at' => now($this->operationsTimezone())->toIso8601String(),
            'latest_check_at' => $this->toOperationsIsoString($lastCheckAt, $this->operationsTimezone(), $this->storageTimezone()),
        ];
    }

    protected function emptyGlobalSummary(): array
    {
        return [
            'active_employees' => 0,
            'attended' => 0,
            'pending' => 0,
            'coverage_percent' => 0,
            'total_checks' => 0,
            'entries' => 0,
            'exits' => 0,
            'unknown' => 0,
            'first_check_at' => null,
            'last_check_at' => null,
            'clocks_total' => 0,
            'clocks_active' => 0,
            'clocks_online' => 0,
            'clocks_offline' => 0,
            'clocks_stale' => 0,
            'last_heartbeat_at' => null,
            'operational_status' => 'nodata',
            'operational_label' => 'Sin relojes',
        ];
    }

    protected function emptyHourlyActivity(): array
    {
        return collect(range(0, 23))
            ->map(fn (int $hour) => [
                'hour' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00',
                'total' => 0,
                'entries' => 0,
                'exits' => 0,
                'unknown' => 0,
            ])->all();
    }

    protected function emptyCharts(): array
    {
        return [
            'attendance_by_location' => ['labels' => [], 'datasets' => []],
            'checks_by_hour' => ['labels' => [], 'datasets' => []],
            'clock_status' => ['labels' => [], 'datasets' => []],
            'entries_vs_exits' => ['labels' => [], 'datasets' => []],
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function buildGlobalSheetRows(array $payload, Carbon $generatedAt): array
    {
        $global = $payload['global'];
        $filters = $payload['filters'];

        return [
            ['Dashboard Corporativo y Reclutamiento'],
            ['Generado', $generatedAt->format('Y-m-d H:i:s'), 'Timezone', $this->operationsTimezone()],
            ['Rango', $filters['range'], 'Desde', $filters['from_date'], 'Hasta', $filters['to_date']],
            [],
            ['Metrica', 'Valor'],
            ['Empleados activos', $global['active_employees']],
            ['Asistieron', $global['attended']],
            ['Pendientes', $global['pending']],
            ['Cobertura %', $global['coverage_percent']],
            ['Total checadas', $global['total_checks']],
            ['Entradas', $global['entries']],
            ['Salidas', $global['exits']],
            ['Desconocidas', $global['unknown']],
            ['Primera checada', $global['first_check_at']],
            ['Ultima checada', $global['last_check_at']],
            ['Relojes total', $global['clocks_total']],
            ['Relojes activos', $global['clocks_active']],
            ['Relojes online', $global['clocks_online']],
            ['Relojes offline', $global['clocks_offline']],
            ['Relojes con rezago', $global['clocks_stale']],
            ['Ultimo heartbeat', $global['last_heartbeat_at']],
            ['Estado operativo', $global['operational_label']],
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function buildLocationSheetRows(array $payload): array
    {
        $rows = [[
            'Unidad',
            'Codigo',
            'Activos',
            'Asistieron',
            'Pendientes',
            'Cobertura %',
            'Total checadas',
            'Entradas',
            'Salidas',
            'Desconocidas',
            'Primera checada',
            'Ultima checada',
            'Relojes total',
            'Relojes activos',
            'Relojes online',
            'Relojes offline',
            'Relojes con rezago',
            'Ultimo heartbeat',
            'Estado operativo',
        ]];

        foreach ($payload['locations'] as $location) {
            $rows[] = [
                $location['name'],
                $location['code'],
                $location['summary']['active_employees'],
                $location['summary']['attended'],
                $location['summary']['pending'],
                $location['summary']['coverage_percent'],
                $location['summary']['total_checks'],
                $location['summary']['entries'],
                $location['summary']['exits'],
                $location['summary']['unknown'],
                $location['summary']['first_check_at'],
                $location['summary']['last_check_at'],
                $location['clocks']['total'],
                $location['clocks']['active'],
                $location['clocks']['online'],
                $location['clocks']['offline'],
                $location['clocks']['stale'],
                $location['clocks']['last_heartbeat_at'],
                $location['clocks']['operational_label'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function buildRankingSheetRows(array $payload): array
    {
        $rows = [[
            'Unidad',
            'Reloj',
            'Serie',
            'Estado',
            'Ultimo heartbeat',
            'Total checadas',
            'Ultima checada',
            'Mensaje de estado',
        ]];

        foreach ($payload['clock_ranking'] as $clock) {
            $rows[] = [
                $clock['location_name'],
                $clock['clock_name'],
                $clock['serial_number'],
                $clock['status_label'],
                $clock['last_heartbeat_at'],
                $clock['total_checks'],
                $clock['last_check_at'],
                $clock['last_status_message'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    protected function buildDetailRows(array $filters): array
    {
        $locations = $this->resolveScopedLocations();
        $normalized = $this->normalizeFilters($filters, $locations);
        $selectedLocationIds = $normalized['selected_locations']->pluck('id')->map(fn ($id) => (int) $id)->all();
        $attendanceBase = $this->attendanceQuery(
            $normalized['from_storage'],
            $normalized['to_storage'],
            $selectedLocationIds,
            $normalized['clock_id']
        );

        if ((clone $attendanceBase)->count() > self::DETAIL_EXPORT_LIMIT) {
            return [];
        }

        $rows = [[
            'Fecha hora',
            'Unidad',
            'Reloj',
            'Serie',
            'Empleado ID',
            'Tipo',
            'Fuente',
        ]];

        $records = $attendanceBase
            ->with(['location:id,name', 'clock:id,clock_name,serial_number'])
            ->orderBy('log_date')
            ->get(['id', 'employee_id', 'location_id', 'device_id', 'log_date', 'log_type', 'source']);

        foreach ($records as $record) {
            $rows[] = [
                $this->toOperationsIsoString($record->log_date, $this->operationsTimezone(), $this->storageTimezone()),
                $record->location?->name,
                $record->clock?->clock_name,
                $record->clock?->serial_number,
                $record->employee_id,
                $this->logTypeLabel((int) $record->log_type),
                $record->source,
            ];
        }

        return $rows;
    }

    protected function logTypeLabel(int $type): string
    {
        if (in_array($type, $this->entryTypes(), true)) {
            return 'Entrada';
        }

        if (in_array($type, $this->exitTypes(), true)) {
            return 'Salida';
        }

        return 'Desconocida';
    }

    protected function buildClockCatalog(Collection $locations): array
    {
        return Clock::query()
            ->whereIn('location_id', $locations->pluck('id')->all())
            ->orderBy('clock_name')
            ->get(['id', 'location_id', 'clock_name', 'serial_number'])
            ->map(fn (Clock $clock) => [
                'id' => (int) $clock->id,
                'location_id' => (int) $clock->location_id,
                'name' => $clock->clock_name ?: 'Reloj #'.$clock->id,
                'serial_number' => $clock->serial_number,
                'label' => trim(($clock->clock_name ?: 'Reloj #'.$clock->id).' '.($clock->serial_number ? '('.$clock->serial_number.')' : '')),
            ])
            ->values()
            ->all();
    }

    protected function transformLocationOptions(Collection $locations): array
    {
        return $locations->map(fn (Location $location) => [
            'id' => (int) $location->id,
            'code' => (string) ($location->code ?? ''),
            'name' => $location->name,
            'label' => trim($location->name.' ('.$this->preferredLocationCode($location).')'),
        ])->values()->all();
    }

    protected function preferredLocationCode(Location $location): string
    {
        return (string) ($location->code ?: $location->fortia_location_id ?: $location->id);
    }

    /**
     * @return array<int, int>
     */
    protected function entryTypes(): array
    {
        return collect(config('attendance.consolidation.entry_log_types', [1]))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function exitTypes(): array
    {
        return collect(config('attendance.consolidation.exit_log_types', [2, 4]))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    protected function resolveRange(string $range, ?string $fromDate, ?string $toDate, string $timezone): array
    {
        $now = now($timezone);

        if ($range === self::RANGE_CUSTOM && $fromDate && $toDate) {
            return [
                Carbon::createFromFormat('Y-m-d', $fromDate, $timezone)->startOfDay(),
                Carbon::createFromFormat('Y-m-d', $toDate, $timezone)->endOfDay(),
            ];
        }

        return match ($range) {
            self::RANGE_YESTERDAY => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            self::RANGE_CURRENT_WEEK => [
                $now->copy()->startOfWeek()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            self::RANGE_FORTNIGHT => $now->day <= 15
                ? [$now->copy()->startOfMonth()->startOfDay(), $now->copy()->startOfMonth()->addDays(14)->endOfDay()]
                : [$now->copy()->startOfMonth()->addDays(15)->startOfDay(), $now->copy()->endOfMonth()->endOfDay()],
            default => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
        };
    }

    protected function percentage(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }

    protected function makeAlert(string $level, string $title, string $message, int $score): array
    {
        return [
            'id' => md5($level.'|'.$title.'|'.$message),
            'level' => $level,
            'title' => $title,
            'message' => $message,
            'score' => $score,
        ];
    }

    protected function isBusinessHours(string $timezone): bool
    {
        $hour = (int) now($timezone)->format('G');

        return $hour >= 7 && $hour < 20;
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

    protected function operationsTimezone(): string
    {
        return (string) config('operations.timezone', 'America/Mexico_City');
    }

    protected function storageTimezone(): string
    {
        return (string) config('operations.storage_timezone', 'UTC');
    }

    protected function timezoneOffsetMinutes(string $timezone, string $storageTimezone): int
    {
        $reference = now('UTC');
        $storageOffset = $reference->copy()->setTimezone($storageTimezone)->utcOffset();
        $operationsOffset = $reference->copy()->setTimezone($timezone)->utcOffset();

        return $operationsOffset - $storageOffset;
    }

    protected function sqliteOffsetModifier(int $offsetMinutes): string
    {
        $sign = $offsetMinutes >= 0 ? '+' : '-';
        $absolute = abs($offsetMinutes);
        $hours = str_pad((string) intdiv($absolute, 60), 2, '0', STR_PAD_LEFT);
        $minutes = str_pad((string) ($absolute % 60), 2, '0', STR_PAD_LEFT);

        return "{$sign}{$hours}:{$minutes}";
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
}
