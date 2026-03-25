<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScopeDeletion;
use App\Models\Location;
use App\Services\Biometrics\AllowedBiometricCandidates;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CatalogSyncController extends Controller
{
    public function __construct(protected AllowedBiometricCandidates $allowedCandidates)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
        ]);

        // Endpoint pensado para la app on-prem (Python) que consume catalogos biometricos.
        $locationId = $validated['location_id'] ?? null;

        $employeesQuery = $this->allowedCandidates->getAllowedEmployeesQuery($locationId);

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

        $this->allowedCandidates->logAllowedEmployees(
            'onprem.catalog.allowed_employees.resolved',
            $locationId
        );

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
        $baseDataQuery = $this->allowedCandidates->getAllowedEmployeesQuery($locationId, 'active');

        $dataQuery = clone $baseDataQuery;
        if ($since) {
            $dataQuery->where('updated_at', '>', $since);
        }

        $employeeColumns = [
            'id',
            'fortia_employee_id',
            'full_name',
            'name',
            'last_name',
            'status',
            'base_location_id',
            'has_fingerprint',
            'updated_at',
        ];
        if (Schema::hasColumn('employees', 'can_check_all_branches')) {
            $employeeColumns[] = 'can_check_all_branches';
        }

        foreach ([
            'has_face_enrollment',
            'face_status',
            'face_samples_count',
            'face_template_version',
            'face_updated_at',
            'face_enabled',
            'face_quality_score',
        ] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $employeeColumns[] = $column;
            }
        }

        $rows = $dataQuery
            ->orderBy('updated_at')
            ->get($employeeColumns);

        $baseTombstonesQuery = $this->allowedCandidates->getAllowedEmployeesQuery($locationId, 'inactive');

        $tombstonesQuery = clone $baseTombstonesQuery;
        if ($since) {
            $tombstonesQuery->where('updated_at', '>', $since);
        }

        $inactiveTombstones = $tombstonesQuery
            ->orderBy('updated_at')
            ->get(['id', 'updated_at'])
            ->map(fn (Employee $employee): array => [
                'employee_id' => (int) $employee->id,
                'deleted_at' => optional($employee->updated_at)->toIso8601String(),
            ])
            ->values();

        $scopeTombstones = collect();
        if ($locationId !== null && Schema::hasTable('employee_scope_deletions')) {
            $scopeTombstonesQuery = EmployeeScopeDeletion::query()
                ->where('scope_location_id', $locationId);

            if ($since) {
                $scopeTombstonesQuery->where('deleted_at', '>', $since);
            }

            $scopeTombstones = $scopeTombstonesQuery
                ->orderBy('deleted_at')
                ->get(['employee_id', 'deleted_at'])
                ->map(fn (EmployeeScopeDeletion $deletion): array => [
                    'employee_id' => (int) $deletion->employee_id,
                    'deleted_at' => optional($deletion->deleted_at)->toIso8601String(),
                ]);
        }

        $tombstones = collect($inactiveTombstones->all())
            ->merge($scopeTombstones)
            ->sortBy('deleted_at')
            ->groupBy('employee_id')
            ->map(function ($items): array {
                $latest = collect($items)->sortByDesc('deleted_at')->first();

                return [
                    'employee_id' => (int) $latest['employee_id'],
                    'deleted_at' => $latest['deleted_at'],
                ];
            })
            ->values();

        $maxUpdatedAt = (clone $baseDataQuery)->max('updated_at');
        $maxInactiveAt = (clone $baseTombstonesQuery)->max('updated_at');
        $maxScopeDeletedAt = null;
        if ($locationId !== null && Schema::hasTable('employee_scope_deletions')) {
            $maxScopeDeletedAt = EmployeeScopeDeletion::query()
                ->where('scope_location_id', $locationId)
                ->max('deleted_at');
        }

        $versionTime = collect([$maxUpdatedAt, $maxInactiveAt, $maxScopeDeletedAt, $since])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sort()
            ->last();

        $this->allowedCandidates->logAllowedEmployees(
            'onprem.employees_catalog.allowed_employees.resolved',
            $locationId
        );

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
                    'can_check_all_branches' => (bool) ($employee->can_check_all_branches ?? false),
                    'has_fingerprint' => (bool) $employee->has_fingerprint,
                    'fingerprint_status' => (string) $employee->fingerprint_status,
                    'has_face_enrollment' => (bool) ($employee->has_face_enrollment ?? false),
                    'face_status' => (string) ($employee->face_status ?? 'none'),
                    'face_enabled' => (bool) ($employee->face_enabled ?? false),
                    'face_samples_count' => (int) ($employee->face_samples_count ?? 0),
                    'face_template_version' => $employee->face_template_version,
                    'face_quality_score' => is_numeric($employee->face_quality_score) ? (int) $employee->face_quality_score : null,
                    'face_updated_at' => optional($employee->face_updated_at)->toIso8601String(),
                    'face_sync_ready' => (bool) ($employee->face_sync_ready ?? false),
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
