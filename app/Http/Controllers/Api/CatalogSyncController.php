<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogSyncController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
        ]);

        // Endpoint pensado para la app on-prem (Python) que consume catalogos biometricos.
        $locationId = $validated['location_id'] ?? null;

        $employeesQuery = Employee::query()->whereIn('status', ['A', 'active']);
        if ($locationId) {
            $employeesQuery->where('base_location_id', $locationId);
        }

        $clocksQuery = Clock::query();
        if ($locationId) {
            $clocksQuery->where('location_id', $locationId);
        }

        $locationsQuery = Location::query();
        if ($locationId) {
            $locationsQuery->where('id', $locationId);
        }

        $company = null;
        if ($locationId) {
            $company = Location::query()->with('company')->find($locationId)?->company;
        }

        if (! $company) {
            $company = Company::query()->orderBy('name')->first();
        }

        return response()->json([
            'employees' => $employeesQuery->orderBy('full_name')->get(),
            'clocks' => $clocksQuery->orderBy('clock_name')->get(),
            'locations' => $locationsQuery->orderBy('name')->get(),
            'company' => $company,
            'version' => now()->format('YmdHis'),
        ]);
    }

    public function employeesCatalog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => [
                'nullable',
                'string',
                'max:40',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $raw = trim((string) $value);
                    if ($raw === '') {
                        return;
                    }
                    if (preg_match('/^\d{14}$/', $raw) === 1) {
                        return;
                    }
                    if (strtotime($raw) !== false) {
                        return;
                    }
                    $fail('El campo since debe ser timestamp YmdHis o fecha valida.');
                },
            ],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        $since = isset($validated['since']) ? $this->parseSince($validated['since']) : null;
        $locationId = $validated['location_id'] ?? null;

        $activeStatuses = ['A', 'active'];
        $inactiveStatuses = ['B', 'inactive'];

        $baseDataQuery = Employee::query()
            ->whereIn('status', $activeStatuses);
        if ($locationId) {
            $baseDataQuery->where('base_location_id', $locationId);
        }

        $dataQuery = clone $baseDataQuery;
        if ($since) {
            $dataQuery->where('updated_at', '>', $since);
        }

        $rows = $dataQuery
            ->orderBy('updated_at')
            ->get(['id', 'fortia_employee_id', 'full_name', 'name', 'last_name', 'status', 'base_location_id', 'updated_at']);

        $baseTombstonesQuery = Employee::query()
            ->whereIn('status', $inactiveStatuses);
        if ($locationId) {
            $baseTombstonesQuery->where('base_location_id', $locationId);
        }

        $tombstonesQuery = clone $baseTombstonesQuery;
        if ($since) {
            $tombstonesQuery->where('updated_at', '>', $since);
        }

        $tombstones = $tombstonesQuery
            ->orderBy('updated_at')
            ->get(['id', 'updated_at'])
            ->map(fn (Employee $employee): array => [
                'employee_id' => (int) $employee->id,
                'deleted_at' => optional($employee->updated_at)->toIso8601String(),
            ])
            ->values();

        $maxUpdatedAt = (clone $baseDataQuery)->max('updated_at');
        $maxInactiveAt = (clone $baseTombstonesQuery)->max('updated_at');
        $versionTime = collect([$maxUpdatedAt, $maxInactiveAt, $since])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sort()
            ->last();

        return response()->json([
            'version' => ($versionTime ?? now())->format('YmdHis'),
            'data' => $rows->map(function (Employee $employee): array {
                $name = trim((string) ($employee->full_name ?: ''));
                if ($name === '') {
                    $name = trim(sprintf('%s %s', (string) $employee->name, (string) $employee->last_name));
                }

                return [
                    'employee_id' => (int) $employee->id,
                    'fortia_employee_id' => (int) $employee->fortia_employee_id,
                    'name' => $name !== '' ? $name : 'Empleado '.$employee->id,
                    'status' => (string) $employee->status,
                    'location_id' => $employee->base_location_id ? (int) $employee->base_location_id : null,
                    'updated_at' => optional($employee->updated_at)->toIso8601String(),
                ];
            })->values(),
            'tombstones' => $tombstones,
        ]);
    }

    private function parseSince(string $value): ?Carbon
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{14}$/', $raw) === 1) {
            return Carbon::createFromFormat('YmdHis', $raw);
        }

        return Carbon::parse($raw);
    }
}
