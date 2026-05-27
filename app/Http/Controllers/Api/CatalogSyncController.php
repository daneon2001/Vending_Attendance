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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CatalogSyncController extends Controller
{
    public function __construct(protected AllowedBiometricCandidates $allowedCandidates)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'integer'],
        ]);

        $locationId = null;
        if (array_key_exists('location_id', $validated) && $validated['location_id'] !== null) {
            $locationId = $this->resolveLocationId((int) $validated['location_id']);
            if ($locationId === null) {
                return response()->json([
                    'message' => 'The selected location id is invalid.',
                    'errors' => [
                        'location_id' => ['The selected location id is invalid.'],
                    ],
                ], 422);
            }
        }

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
        $startedAt = microtime(true);
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
            'location_id' => ['nullable', 'integer'],
        ]);

        $since = isset($validated['since']) ? $this->parseSince($validated['since']) : null;
        $locationId = null;
        if (array_key_exists('location_id', $validated) && $validated['location_id'] !== null) {
            $locationId = $this->resolveLocationId((int) $validated['location_id']);
            if ($locationId === null) {
                return response()->json([
                    'message' => 'The selected location id is invalid.',
                    'errors' => [
                        'location_id' => ['The selected location id is invalid.'],
                    ],
                ], 422);
            }
        }

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

        if (Schema::hasColumn('employees', 'check_scope')) {
            $employeeColumns[] = 'check_scope';
        }

        foreach ([
            'fingerprint_status',
            'has_face_enrollment',
            'face_status',
            'face_samples_count',
            'face_template_version',
            'face_updated_at',
            'face_enabled',
            'face_quality_score',
            'face_sync_ready',
        ] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $employeeColumns[] = $column;
            }
        }

        $rows = $dataQuery
            ->orderBy('updated_at')
            ->get($employeeColumns);

        $allowedLocationMap = $this->loadAllowedLocationMap($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

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

        $payload = [
            'version' => ($versionTime ?? now())->format('YmdHis'),
            'data' => $rows->map(function (Employee $employee) use ($allowedLocationMap): array {
                $name = trim((string) ($employee->full_name ?: ''));
                if ($name === '') {
                    $name = trim(sprintf('%s %s', (string) $employee->name, (string) $employee->last_name));
                }

                $resolvedCheckScope = $this->resolveCheckScope($employee);

                return [
                    'employee_id' => (int) $employee->id,
                    'fortia_employee_id' => (int) $employee->fortia_employee_id,
                    'name' => $name !== '' ? $name : 'Empleado '.$employee->id,
                    'status' => (string) $employee->status,
                    'location_id' => $employee->base_location_id ? (int) $employee->base_location_id : null,
                    'base_location_id' => $employee->base_location_id ? (int) $employee->base_location_id : null,
                    'can_check_all_branches' => (bool) ($employee->can_check_all_branches ?? false),
                    'check_scope' => $resolvedCheckScope,
                    'allowed_location_ids' => $allowedLocationMap[(int) $employee->id] ?? [],
                    'has_fingerprint' => (bool) $employee->has_fingerprint,
                    'fingerprint_status' => (string) ($employee->fingerprint_status ?? 'none'),
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
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $bodyBytes = is_string($payloadJson) ? strlen($payloadJson) : 0;

        Log::info('onprem.employees_catalog.response', [
            'location_id' => $locationId,
            'since' => $validated['since'] ?? null,
            'data_count' => $rows->count(),
            'tombstones_count' => $tombstones->count(),
            'payload_bytes' => $bodyBytes,
            'duration_ms' => $durationMs,
        ]);

        if ($durationMs > 10000) {
            Log::warning('onprem.employees_catalog.slow', [
                'location_id' => $locationId,
                'since' => $validated['since'] ?? null,
                'data_count' => $rows->count(),
                'tombstones_count' => $tombstones->count(),
                'payload_bytes' => $bodyBytes,
                'duration_ms' => $durationMs,
            ]);
        }

        return response()->json($payload);
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

    private function resolveLocationId(int $locationId): ?int
    {
        $query = Location::query()->where('id', $locationId);
        if (Schema::hasColumn('locations', 'fortia_location_id')) {
            $query->orWhere('fortia_location_id', $locationId);
        }
        if (Schema::hasColumn('locations', 'code')) {
            $query->orWhere('code', (string) $locationId);
        }

        $resolvedLocationId = $query->value('id');
        if (! is_numeric($resolvedLocationId)) {
            return null;
        }

        return (int) $resolvedLocationId;
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<int, int>>
     */
    private function loadAllowedLocationMap(array $employeeIds): array
    {
        if (empty($employeeIds) || ! Schema::hasTable('employee_allowed_locations')) {
            return [];
        }

        $rows = DB::table('employee_allowed_locations')
            ->select('employee_id', 'location_id')
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('employee_id')
            ->orderBy('location_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;
            $locationId = (int) $row->location_id;

            if (! isset($map[$employeeId])) {
                $map[$employeeId] = [];
            }

            $map[$employeeId][] = $locationId;
        }

        return $map;
    }

    private function resolveCheckScope(Employee $employee): string
    {
        $checkScope = trim((string) ($employee->check_scope ?? ''));

        if ($checkScope !== '') {
            return strtoupper($checkScope);
        }

        return (bool) ($employee->can_check_all_branches ?? false)
            ? 'ANY_BRANCH'
            : 'HOME_ONLY';
    }
}
