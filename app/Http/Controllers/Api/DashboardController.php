<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'range' => ['nullable', 'in:today,7d,30d,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        $range = $request->input('range', '7d');
        $locationId = $request->integer('location_id');

        [$from, $to] = $this->resolveRange($range, $request->input('from'), $request->input('to'));

        if (app()->environment('local')) {
            Log::debug('[Dashboard] incoming summary request', [
                'range' => $range,
                'raw_from' => $request->input('from'),
                'raw_to' => $request->input('to'),
                'normalized_from' => $from->toDateTimeString(),
                'normalized_to' => $to->toDateTimeString(),
                'location_id' => $locationId,
            ]);
        }

        $attendanceBase = fn () => AttendanceLog::query()
            ->whereBetween('log_date', [$from, $to])
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId));

        $totalLogs = $attendanceBase()->count();

        $presenceRecords = AttendanceLog::query()
            ->whereBetween('log_date', [$from, $to])
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->selectRaw('DATE(log_date) as day')
            ->selectRaw('COUNT(DISTINCT COALESCE(employee_id, fortia_employee_id)) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $presenceSeries = $this->buildSeriesForPeriod($presenceRecords, $from, $to);

        $employeesQuery = Employee::query()
            ->when($locationId, fn ($query) => $query->where('base_location_id', $locationId));

        $employeeStatus = $employeesQuery
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get();

        $clockStatus = Clock::query()
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->select('monitoring_status', DB::raw('COUNT(*) as total'))
            ->groupBy('monitoring_status')
            ->get();

        $topBranches = $this->buildTopBranches($from, $to, $locationId);

        $totalEmployees = $employeesQuery->count();

        $resolvedRange = [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ];

        $response = [
            'refreshed_at' => now()->toIso8601String(),
            'filters' => [
                'range' => $range,
                'location_id' => $locationId,
            ],
            'resolved_range' => $resolvedRange,
            'kpis' => [
                'attendance_total' => $totalLogs,
                'attendance_average' => $this->calculateAverage($totalLogs, $from, $to),
                'employees_total' => $totalEmployees,
                'employees_active' => $this->extractStatusTotal($employeeStatus, ['A', 'active']),
                'employees_inactive' => $this->extractStatusTotal($employeeStatus, ['B', 'inactive']),
                'clocks_online' => $this->extractClockStatus($clockStatus, 'online'),
                'clocks_warning' => $this->extractClockStatus($clockStatus, 'warning'),
                'clocks_offline' => $this->extractClockStatus($clockStatus, 'offline'),
            ],
            'presence_series' => $presenceSeries,
            'employee_status' => $this->formatStatusDataset($employeeStatus),
            'clock_status' => $this->formatClockDataset($clockStatus),
            'top_branches' => $topBranches,
        ];

        if (app()->environment('local')) {
            Log::debug('[Dashboard] response aggregates', [
                'presence_points' => count($presenceSeries['labels']),
                'top_branches_points' => count($topBranches['labels']),
                'top_branches_mode' => $topBranches['mode'] ?? null,
            ]);
        }

        return response()->json($response);
    }

    protected function resolveRange(string $range, ?string $fromInput, ?string $toInput): array
    {
        $now = now();

        if ($range === 'custom' && ($fromInput || $toInput)) {
            $startReference = $fromInput ?? $toInput;
            $endReference = $toInput ?? $fromInput ?? $startReference;

            $from = Carbon::parse($startReference)->startOfDay();
            $to = Carbon::parse($endReference)->endOfDay();

            if ($from->greaterThan($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            return [$from, $to];
        }

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            default => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()], // 7d
        };
    }

    protected function buildSeriesForPeriod(Collection $records, Carbon $from, Carbon $to): array
    {
        $map = $records->pluck('total', 'day');

        $labels = [];
        $values = [];

        $period = new CarbonPeriod($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay());

        foreach ($period as $date) {
            $key = $date->toDateString();
            $labels[] = $date->format('d/m');
            $values[] = (int) ($map[$key] ?? 0);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    protected function calculateAverage(int $total, Carbon $from, Carbon $to): float
    {
        $days = max($from->diffInDays($to) + 1, 1);

        return round($total / $days, 1);
    }

    protected function extractStatusTotal(Collection $records, array $keys): int
    {
        return $records
            ->filter(fn ($row) => in_array($row->status, $keys, true))
            ->sum('total');
    }

    protected function extractClockStatus(Collection $records, string $key): int
    {
        return (int) $records
            ->firstWhere('monitoring_status', $key)?->total ?? 0;
    }

    protected function formatStatusDataset(Collection $records): array
    {
        $labels = [];
        $values = [];

        $mappings = [
            'A' => 'Activos',
            'B' => 'Baja',
            'active' => 'Activos',
            'inactive' => 'Inactivos',
        ];

        foreach ($records as $row) {
            $status = $row->status ?? 'Desconocido';
            $labels[] = $mappings[$status] ?? ucfirst($status);
            $values[] = (int) $row->total;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    protected function formatClockDataset(Collection $records): array
    {
        $labels = [];
        $values = [];

        $mappings = [
            'online' => 'En l\u00ednea',
            'warning' => 'Con alertas',
            'offline' => 'Sin conexi\u00f3n',
        ];

        foreach ($records as $row) {
            $key = $row->monitoring_status ?? 'desconocido';
            $labels[] = $mappings[$key] ?? ucfirst($key);
            $values[] = (int) $row->total;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    protected function buildTopBranches(Carbon $from, Carbon $to, ?int $locationId = null): array
    {
        $resolvedLocationExpression = "COALESCE(attendance_logs.location_id, employees.base_location_id)";

        $baseQuery = function () use ($from, $to, $locationId, $resolvedLocationExpression) {
            return AttendanceLog::query()
                ->leftJoin('employees', 'employees.id', '=', 'attendance_logs.employee_id')
                ->whereBetween('attendance_logs.log_date', [$from, $to])
                ->whereRaw("$resolvedLocationExpression IS NOT NULL")
                ->when($locationId, fn ($query) => $query->whereRaw("$resolvedLocationExpression = ?", [$locationId]));
        };

        $dailyCounts = $baseQuery()
            ->selectRaw('DATE(attendance_logs.log_date) as day')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("$resolvedLocationExpression as resolved_location_id")
            ->groupBy(DB::raw('DATE(attendance_logs.log_date)'), DB::raw($resolvedLocationExpression));

        $incidents = DB::query()
            ->fromSub($dailyCounts, 'daily')
            ->leftJoin('locations', 'locations.id', '=', 'daily.resolved_location_id')
            ->selectRaw("daily.resolved_location_id as location_id, COALESCE(locations.name, CONCAT('Unidad #', daily.resolved_location_id)) as location_name, SUM(CASE WHEN daily.total < 2 THEN 1 ELSE 0 END) as incidents, SUM(daily.total) as logs")
            ->groupBy('daily.resolved_location_id', 'locations.name')
            ->orderByDesc('incidents')
            ->limit(5)
            ->get();

        $mode = 'incidents';
        $labels = $incidents->pluck('location_name')->toArray();
        $values = $incidents->pluck('incidents')->map(fn ($value) => (int) $value)->toArray();

        if (! array_sum($values)) {
            $volumeSubquery = $baseQuery()
                ->selectRaw("$resolvedLocationExpression as resolved_location_id")
                ->selectRaw('COUNT(*) as total')
                ->groupBy(DB::raw($resolvedLocationExpression));

            $fallback = DB::query()
                ->fromSub($volumeSubquery, 'volume')
                ->leftJoin('locations', 'locations.id', '=', 'volume.resolved_location_id')
                ->selectRaw("volume.resolved_location_id as location_id, COALESCE(locations.name, CONCAT('Unidad #', volume.resolved_location_id)) as location_name, volume.total")
                ->orderByDesc('volume.total')
                ->limit(5)
                ->get();

            $mode = 'volume';
            $labels = $fallback->pluck('location_name')->toArray();
            $values = $fallback->pluck('total')->map(fn ($value) => (int) $value)->toArray();
        }

        return [
            'mode' => $mode,
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
