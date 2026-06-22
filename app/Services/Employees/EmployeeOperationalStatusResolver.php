<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EmployeeStatusChange;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EmployeeOperationalStatusResolver
{
    /**
     * @var array<int, string>
     */
    private const ACTIVE_STATUSES = ['A', 'ACTIVE', 'ACTIVO'];

    public function isEmployeeActiveOnDate(Employee $employee, Carbon $date, ?Collection $timeline = null): bool
    {
        $timeline ??= collect();
        $dateKey = $date->copy()->setTimezone($this->operationsTimezone())->toDateString();

        $lastChange = $timeline
            ->filter(fn (array $change): bool => $change['effective_date'] <= $dateKey)
            ->last();

        if (is_array($lastChange)) {
            return $this->isActiveStatus($lastChange['new_status'] ?? null);
        }

        $firstFutureChange = $timeline
            ->first(fn (array $change): bool => $change['effective_date'] > $dateKey);

        if (is_array($firstFutureChange) && filled($firstFutureChange['old_status'] ?? null)) {
            return $this->isActiveStatus($firstFutureChange['old_status'] ?? null);
        }

        return $this->isActiveStatus($employee->status);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array{dates: array<int, string>, by_employee_date: array<int, array<string, bool>>, eligible_employee_ids: array<int, int>}
     */
    public function buildEligibilitySnapshot(Collection $employees, Carbon $fromLocal, Carbon $toLocal): array
    {
        $employees = $employees
            ->filter(fn ($employee) => $employee instanceof Employee)
            ->keyBy(fn (Employee $employee): int => (int) $employee->id);

        if ($employees->isEmpty()) {
            return [
                'dates' => [],
                'by_employee_date' => [],
                'eligible_employee_ids' => [],
            ];
        }

        $dates = collect(new CarbonPeriod(
            $fromLocal->copy()->startOfDay(),
            '1 day',
            $toLocal->copy()->startOfDay()
        ))
            ->map(fn (Carbon $date): string => $date->copy()->setTimezone($this->operationsTimezone())->toDateString())
            ->values()
            ->all();

        $timelines = $this->loadEmployeeStatusTimelines($employees);
        $eligibilityMap = [];
        $eligibleEmployeeIds = [];

        foreach ($employees as $employeeId => $employee) {
            $timeline = $timelines[$employeeId] ?? collect();
            $hasEligibleDay = false;

            foreach ($dates as $dateKey) {
                $isActive = $this->isEmployeeActiveOnDate(
                    $employee,
                    Carbon::createFromFormat('Y-m-d', $dateKey, $this->operationsTimezone()),
                    $timeline
                );

                $eligibilityMap[$employeeId][$dateKey] = $isActive;
                $hasEligibleDay = $hasEligibleDay || $isActive;
            }

            if ($hasEligibleDay) {
                $eligibleEmployeeIds[] = (int) $employeeId;
            }
        }

        return [
            'dates' => $dates,
            'by_employee_date' => $eligibilityMap,
            'eligible_employee_ids' => $eligibleEmployeeIds,
        ];
    }

    public function isActiveStatus(?string $status): bool
    {
        $normalized = strtoupper(trim((string) $status));

        return in_array($normalized, self::ACTIVE_STATUSES, true);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, Collection<int, array{effective_date:string,old_status:?string,new_status:?string}>>
     */
    protected function loadEmployeeStatusTimelines(Collection $employees): array
    {
        if (! Schema::hasTable('employee_status_changes')) {
            return [];
        }

        $employeeIds = $employees->keys()->map(fn ($id): int => (int) $id)->all();
        $fortiaEmployeeIds = $employees
            ->pluck('fortia_employee_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($employeeIds === [] && $fortiaEmployeeIds === []) {
            return [];
        }

        $rows = EmployeeStatusChange::query()
            ->when($employeeIds !== [] || $fortiaEmployeeIds !== [], function ($query) use ($employeeIds, $fortiaEmployeeIds): void {
                $query->where(function ($builder) use ($employeeIds, $fortiaEmployeeIds): void {
                    if ($employeeIds !== []) {
                        $builder->whereIn('employee_id', $employeeIds);
                    }

                    if ($fortiaEmployeeIds !== []) {
                        $method = $employeeIds !== [] ? 'orWhereIn' : 'whereIn';
                        $builder->{$method}('fortia_employee_id', $fortiaEmployeeIds);
                    }
                });
            })
            ->orderBy('changed_at')
            ->get();

        $employeesByFortia = $employees
            ->filter(fn (Employee $employee) => $employee->fortia_employee_id !== null)
            ->keyBy(fn (Employee $employee): int => (int) $employee->fortia_employee_id);

        $timelines = [];

        foreach ($rows as $row) {
            $employeeId = $row->employee_id ? (int) $row->employee_id : null;

            if (! $employeeId && $row->fortia_employee_id !== null) {
                $employeeId = (int) optional($employeesByFortia->get((int) $row->fortia_employee_id))->id;
            }

            if (! $employeeId || ! $employees->has($employeeId)) {
                continue;
            }

            $effectiveAt = $this->resolveEffectiveChangedAt($row);

            if (! $effectiveAt) {
                continue;
            }

            $timelines[$employeeId] ??= collect();
            $timelines[$employeeId]->push([
                'effective_date' => $effectiveAt->copy()->setTimezone($this->operationsTimezone())->toDateString(),
                'old_status' => $row->old_status,
                'new_status' => $row->new_status,
            ]);
        }

        return $timelines;
    }

    protected function resolveEffectiveChangedAt(EmployeeStatusChange $change): ?Carbon
    {
        $remoteUpdatedAt = is_array($change->meta ?? null)
            ? ($change->meta['remote_updated_at'] ?? null)
            : null;

        if (filled($remoteUpdatedAt)) {
            return Carbon::parse((string) $remoteUpdatedAt)->setTimezone($this->operationsTimezone());
        }

        if ($change->changed_at) {
            return $change->changed_at->copy()->setTimezone($this->operationsTimezone());
        }

        return null;
    }

    protected function operationsTimezone(): string
    {
        return (string) config('operations.timezone', config('app.timezone', 'America/Mexico_City'));
    }
}
