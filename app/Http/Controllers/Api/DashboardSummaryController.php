<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Employee;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(['today', '7d', '30d', 'custom'])],
            'from_date' => ['required_if:range,custom', 'nullable', 'date_format:d/m/Y'],
            'to_date' => ['required_if:range,custom', 'nullable', 'date_format:d/m/Y'],
            'unit_id' => ['nullable', 'exists:locations,id'],
        ]);

        $timezone = config('app.timezone', 'UTC');
        $range = $validated['range'] ?? 'today';
        [$startLocal, $endLocal] = $this->resolveRange($range, $validated, $timezone);

        $unitId = $validated['unit_id'] ?? null;
        $employeeBaseLocationId = $unitId ? $this->resolveEmployeeBaseLocationId((int) $unitId) : null;
        $startUtc = $startLocal->copy()->setTimezone('UTC');
        $endUtc = $endLocal->copy()->setTimezone('UTC');

        $attendanceBase = AttendanceLog::query()
            ->whereBetween('log_date', [$startUtc, $endUtc]);

        if ($unitId) {
            $attendanceBase->where('location_id', $unitId);
        }

        $checkinsTotal = (clone $attendanceBase)->count();

        $peoplePresence = (clone $attendanceBase)
            ->selectRaw("DATE(CONVERT_TZ(log_date, 'UTC', ?)) as local_day, COUNT(DISTINCT employee_id) as total", [$timezone])
            ->groupByRaw("DATE(CONVERT_TZ(log_date, 'UTC', ?))", [$timezone])
            ->orderBy('local_day')
            ->get();

        $peopleChart = [
            'labels' => $peoplePresence->map(fn ($row) => Carbon::parse($row->local_day)->format('d/m'))->toArray(),
            'values' => $peoplePresence->pluck('total')->map(fn ($value) => (int) $value)->toArray(),
        ];

        $kpis = [
            'checkins_total' => $checkinsTotal,
            'employees_active' => $this->countActiveEmployees($employeeBaseLocationId),
            'clocks_with_alerts' => $this->countClocksByStatus('warning', $unitId),
            'clocks_offline' => $this->countClocksByStatus('offline', $unitId),
        ];

        $charts = [
            'people_present_by_day' => $peopleChart,
            'employees_status' => $this->buildEmployeeStatusChart($employeeBaseLocationId),
            'clock_health' => $this->buildClockHealthChart($unitId),
        ];

        return response()->json([
            'meta' => [
                'range' => $range,
                'from' => $startLocal->toIso8601String(),
                'to' => $endLocal->toIso8601String(),
                'unit_id' => $unitId,
                'generated_at_local' => now($timezone)->format('d/m/Y H:i:s'),
            ],
            'kpis' => $kpis,
            'charts' => $charts,
        ]);
    }

    protected function resolveRange(string $range, array $validated, string $timezone): array
    {
        $now = now($timezone);

        if ($range === 'custom') {
            $fromString = $validated['from_date'] ?? null;
            $toString = $validated['to_date'] ?? null;

            if (! $fromString || ! $toString) {
                abort(422, 'Debes proporcionar fechas Desde y Hasta para el rango personalizado.');
            }

            $from = Carbon::createFromFormat('d/m/Y', $fromString, $timezone)->startOfDay();
            $to = Carbon::createFromFormat('d/m/Y', $toString, $timezone)->endOfDay();

            if ($from->greaterThan($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            return [$from, $to];
        }

        if ($range === '7d') {
            return [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()];
        }

        if ($range === '30d') {
            return [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()];
        }

        return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
    }

    protected function countActiveEmployees(?int $employeeBaseLocationId): int
    {
        $query = Employee::query()->where('status', 'A');

        if ($employeeBaseLocationId) {
            $query->where('base_location_id', $employeeBaseLocationId);
        }

        return $query->count();
    }

    protected function countClocksByStatus(string $status, ?int $unitId): int
    {
        $query = Clock::query()->where('monitoring_status', $status);

        if ($unitId) {
            $query->where('location_id', $unitId);
        }

        return $query->count();
    }

    protected function buildEmployeeStatusChart(?int $employeeBaseLocationId): array
    {
        $query = Employee::query()->select('status', DB::raw('COUNT(*) as total'));

        if ($employeeBaseLocationId) {
            $query->where('base_location_id', $employeeBaseLocationId);
        }

        $statusRows = $query->groupBy('status')->get()->keyBy('status');

        return [
            'labels' => ['Activos', 'Bajas'],
            'values' => [
                (int) ($statusRows['A']->total ?? 0),
                (int) ($statusRows['B']->total ?? 0),
            ],
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

        if (\Illuminate\Support\Facades\Schema::hasColumn('locations', 'fortia_location_id')) {
            $locationQuery->orWhere('fortia_location_id', $unitId);
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('locations', 'code')) {
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

    protected function buildClockHealthChart(?int $unitId): array
    {
        $query = Clock::query()
            ->select('monitoring_status', DB::raw('COUNT(*) as total'));

        if ($unitId) {
            $query->where('location_id', $unitId);
        }

        $rows = $query->groupBy('monitoring_status')->get()->keyBy('monitoring_status');

        return [
            'labels' => ['En línea', 'Con alertas', 'Sin conexión'],
            'values' => [
                (int) ($rows['online']->total ?? 0),
                (int) ($rows['warning']->total ?? 0),
                (int) ($rows['offline']->total ?? 0),
            ],
        ];
    }
}
