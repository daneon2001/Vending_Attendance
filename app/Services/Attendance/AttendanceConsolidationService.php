<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDaily;
use App\Models\AttendanceRecord;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class AttendanceConsolidationService
{
    /**
     * Base consolidator for raw marks -> daily summary.
     * This keeps behavior configurable and can be evolved later with schedule rules.
     */
    public function consolidateRange(CarbonInterface $from, CarbonInterface $to, array $filters = []): int
    {
        $total = 0;
        $period = CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay());

        foreach ($period as $workDate) {
            $total += $this->consolidateDay(Carbon::instance($workDate), $filters);
        }

        return $total;
    }

    public function consolidateDay(CarbonInterface $workDate, array $filters = []): int
    {
        $dayStart = $workDate->copy()->startOfDay();
        $dayEnd = $workDate->copy()->endOfDay();

        $records = AttendanceRecord::query()
            ->with('employee')
            ->whereBetween('log_date', [$dayStart, $dayEnd])
            ->where('attendance_status', '!=', AttendanceRecord::STATUS_ANULADA)
            ->when(! empty($filters['employee_id']), fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->when(! empty($filters['location_id']), fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when(! empty($filters['company_id']), fn ($query) => $query->where('company_id', $filters['company_id']))
            ->orderBy('employee_id')
            ->orderBy('log_date')
            ->get()
            ->groupBy('employee_id');

        $updates = 0;

        foreach ($records as $employeeId => $employeeRecords) {
            $payload = $this->buildDailyPayload($employeeRecords);

            AttendanceDaily::query()->updateOrCreate(
                [
                    'employee_id' => $employeeId,
                    'work_date' => $workDate->toDateString(),
                    'location_id' => $payload['location_id'],
                ],
                [
                    'company_id' => $payload['company_id'],
                    'first_check_in_at' => $payload['first_check_in_at'],
                    'last_check_out_at' => $payload['last_check_out_at'],
                    'total_logs' => $payload['total_logs'],
                    'total_in' => $payload['total_in'],
                    'total_out' => $payload['total_out'],
                    'late_minutes' => $payload['late_minutes'],
                    'is_absence' => $payload['is_absence'],
                    'consolidation_status' => 'ready',
                    'consolidated_payload' => $payload['meta'],
                    'consolidated_at' => now(),
                ]
            );

            $updates++;
        }

        return $updates;
    }

    protected function buildDailyPayload(Collection $records): array
    {
        $rules = config('attendance.consolidation', []);
        $entryTypes = collect($rules['entry_log_types'] ?? [1])->map(fn ($value) => (int) $value)->all();
        $exitTypes = collect($rules['exit_log_types'] ?? [2, 4])->map(fn ($value) => (int) $value)->all();
        $dedupWindowSeconds = (int) ($rules['dedup_window_seconds'] ?? 90);

        $deduped = $this->deduplicateRecords($records, $dedupWindowSeconds);
        $firstEntry = $deduped->first(fn (AttendanceRecord $record) => in_array((int) $record->log_type, $entryTypes, true));
        $lastExit = $deduped->last(fn (AttendanceRecord $record) => in_array((int) $record->log_type, $exitTypes, true));

        $totalIn = $deduped->filter(fn (AttendanceRecord $record) => in_array((int) $record->log_type, $entryTypes, true))->count();
        $totalOut = $deduped->filter(fn (AttendanceRecord $record) => in_array((int) $record->log_type, $exitTypes, true))->count();

        return [
            'company_id' => $deduped->first()?->company_id,
            'location_id' => $deduped->first()?->location_id,
            'first_check_in_at' => $firstEntry?->log_date,
            'last_check_out_at' => $lastExit?->log_date,
            'total_logs' => $deduped->count(),
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'late_minutes' => 0,
            'is_absence' => $deduped->isEmpty(),
            'meta' => [
                'rules' => [
                    'entry_log_types' => $entryTypes,
                    'exit_log_types' => $exitTypes,
                    'dedup_window_seconds' => $dedupWindowSeconds,
                    'entry_tolerance_minutes' => (int) ($rules['entry_tolerance_minutes'] ?? 0),
                    'exit_tolerance_minutes' => (int) ($rules['exit_tolerance_minutes'] ?? 0),
                ],
                'raw_count' => $records->count(),
                'deduped_count' => $deduped->count(),
            ],
        ];
    }

    protected function deduplicateRecords(Collection $records, int $windowSeconds): Collection
    {
        if ($windowSeconds <= 0 || $records->count() < 2) {
            return $records->values();
        }

        $ordered = $records->sortBy('log_date')->values();
        $deduped = collect();

        foreach ($ordered as $record) {
            /** @var AttendanceRecord|null $previous */
            $previous = $deduped->last();
            if (! $previous) {
                $deduped->push($record);
                continue;
            }

            $sameType = (int) $previous->log_type === (int) $record->log_type;
            $secondsGap = abs($record->log_date->diffInSeconds($previous->log_date, false));

            if ($sameType && $secondsGap <= $windowSeconds) {
                continue;
            }

            $deduped->push($record);
        }

        return $deduped->values();
    }
}
