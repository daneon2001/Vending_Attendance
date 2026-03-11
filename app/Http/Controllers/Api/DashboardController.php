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
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'range' => ['nullable', 'in:today,7d,30d,custom'],
            'from_date' => ['required_if:range,custom', 'nullable', 'date_format:d/m/Y'],
            'to_date' => ['required_if:range,custom', 'nullable', 'date_format:d/m/Y'],
            'unit_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        $range = $request->input('range', 'today');
        $unitId = $request->integer('unit_id');

        [$from, $to] = $this->resolveRange($range, $request->input('from_date'), $request->input('to_date'));

        if (app()->environment('local')) {
            Log::debug('[Dashboard] incoming summary request', [
                'range' => $range,
                'raw_from' => $request->input('from'),
                'raw_to' => $request->input('to'),
                'normalized_from' => $from->toDateTimeString(),
                'normalized_to' => $to->toDateTimeString(),
                'unit_id' => $unitId,
            ]);
        }

        $attendanceBase = fn () => AttendanceLog::query()
            ->whereBetween('log_date', [$from, $to])
            ->when($unitId, fn ($query) => $query->where('location_id', $unitId));

        $totalLogs = $attendanceBase()->count();

        $presenceRecords = AttendanceLog::query()
            ->whereBetween('log_date', [$from, $to])
            ->when($unitId, fn ($query) => $query->where('location_id', $unitId))
            ->selectRaw('DATE(log_date) as day')
            ->selectRaw('COUNT(DISTINCT COALESCE(employee_id, fortia_employee_id)) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $presenceSeries = $this->buildSeriesForPeriod($presenceRecords, $from, $to);

        $employeesQuery = Employee::query()
            ->when($unitId, fn ($query) => $query->where('base_location_id', $unitId));

        $employeeStatus = $employeesQuery
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get();

        $clockStatus = Clock::query()
            ->when($unitId, fn ($query) => $query->where('location_id', $unitId))
            ->select('monitoring_status', DB::raw('COUNT(*) as total'))
            ->groupBy('monitoring_status')
            ->get();

        $topBranches = $this->buildTopBranches($from, $to, $unitId);

        $totalEmployees = $employeesQuery->count();

        $resolvedRange = [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ];

        $timezone = config('app.timezone', 'UTC');

        $meta = [
            'range' => $range,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'unit_id' => $unitId,
            'generated_at_local' => now($timezone)->format('d/m/Y H:i:s'),
        ];

        $kpis = [
            'checkins_total' => $totalLogs,
            'employees_active' => $this->extractStatusTotal($employeeStatus, ['A', 'active']),
            'clocks_with_alerts' => $this->extractClockStatus($clockStatus, 'warning'),
            'clocks_offline' => $this->extractClockStatus($clockStatus, 'offline'),
        ];

        $employeesStatusDataset = $this->formatStatusDataset($employeeStatus);
        $clockDataset = $this->formatClockDataset($clockStatus);

        $charts = [
            'people_present_by_day' => $presenceSeries,
            'employees_status' => $employeesStatusDataset,
            'clock_health' => $clockDataset,
            'top_branches' => $topBranches,
        ];

        $isEmpty = $this->chartsAreEmpty($presenceSeries, $employeesStatusDataset, $clockDataset);
        $message = $isEmpty ? 'No hay datos para el rango seleccionado.' : null;

        $response = [
            'ok' => true,
            'empty' => $isEmpty,
            'message' => $message,
            'meta' => $meta,
            'kpis' => $kpis,
            'charts' => $charts,
        ];

        if (app()->environment('local')) {
            Log::debug('[Dashboard] response aggregates', [
                'presence_points' => count($presenceSeries['labels']),
                'top_branches_points' => count($topBranches['labels']),
                'top_branches_mode' => $topBranches['mode'] ?? null,
                'is_empty' => $isEmpty,
            ]);
        }

        return response()->json($response);
    }

    protected function resolveRange(string $range, ?string $fromInput, ?string $toInput): array
    {
        $now = now();

        if ($range === 'custom') {
            if (! $fromInput || ! $toInput) {
                throw ValidationException::withMessages([
                    'from_date' => 'Debes proporcionar fechas de inicio y fin.',
                ]);
            }

            try {
                $from = Carbon::createFromFormat('d/m/Y', $fromInput)->startOfDay();
                $to = Carbon::createFromFormat('d/m/Y', $toInput)->endOfDay();
            } catch (\Throwable $th) {
                throw ValidationException::withMessages([
                    'from_date' => 'El formato de fecha debe ser dd/mm/aaaa.',
                ]);
            }

            if ($from->greaterThan($to)) {
                throw ValidationException::withMessages([
                    'from_date' => 'La fecha inicial no puede ser mayor a la final.',
                ]);
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

    protected function chartsAreEmpty(array $presenceSeries, array $employeeDataset, array $clockDataset): bool
    {
        $presenceTotal = array_sum($presenceSeries['values'] ?? []);
        $employeeTotal = array_sum($employeeDataset['values'] ?? []);
        $clockTotal = array_sum($clockDataset['values'] ?? []);

        return ($presenceTotal + $employeeTotal + $clockTotal) === 0;
    }
}
