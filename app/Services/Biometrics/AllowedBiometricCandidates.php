<?php

namespace App\Services\Biometrics;

use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AllowedBiometricCandidates
{
    private const ACTIVE_EMPLOYEE_STATUSES = ['A', 'active'];

    private const INACTIVE_EMPLOYEE_STATUSES = ['B', 'inactive'];

    private const ACTIVE_ENROLMENT_STATUSES = ['enrolled', 'active'];

    private const FACE_SYNC_READY_STATUSES = ['enrolled', 'ready'];

    public function getAllowedEmployeesQuery(?int $currentBranchId, string $employeeScope = 'active'): Builder
    {
        $query = Employee::query();

        $this->applyEmployeeScope($query, $currentBranchId, $employeeScope);

        return $query;
    }

    public function getAllowedCandidatesQuery(
        ?int $currentBranchId,
        ?string $biometricType = null,
        string $employeeScope = 'active'
    ): Builder {
        $query = EmployeeFingerprint::query()
            ->with($this->employeeRelationSelect())
            ->whereNotNull('employee_id')
            ->whereNotNull('vendor_template_id')
            ->where('vendor_template_id', '<>', '')
            ->whereNotNull('template_b64')
            ->where('template_b64', '<>', '')
            ->whereNull('deleted_at')
            ->whereIn('status', self::ACTIVE_ENROLMENT_STATUSES)
            ->whereIn(
                'employee_id',
                $this->getAllowedEmployeesQuery($currentBranchId, $employeeScope)->select('id')
            );

        $this->applyBiometricTypeScope($query, $biometricType);

        return $query;
    }

    public function logAllowedEmployees(
        string $context,
        ?int $currentBranchId,
        string $employeeScope = 'active'
    ): void {
        $query = $this->getAllowedEmployeesQuery($currentBranchId, $employeeScope);

        $branchAssignedTotal = null;
        $globalPermissionTotal = 0;
        $selectedPermissionTotal = 0;
        $branchCandidates = $this->resolveLocationCandidates($currentBranchId);

        if ($currentBranchId !== null) {
            $branchAssignedQuery = Employee::query()
                ->whereIn('status', $this->resolveEmployeeStatuses($employeeScope))
                ->whereIn('base_location_id', $branchCandidates);
            $branchAssignedTotal = $branchAssignedQuery->count();

            if ($this->hasGlobalBranchFlag()) {
                $globalPermissionTotal = Employee::query()
                    ->whereIn('status', $this->resolveEmployeeStatuses($employeeScope))
                    ->where('can_check_all_branches', true)
                    ->count();
            }

            if ($this->hasCheckScopeColumn() && $this->hasSelectedLocationsTable()) {
                $selectedPermissionTotal = Employee::query()
                    ->whereIn('status', $this->resolveEmployeeStatuses($employeeScope))
                    ->where('check_scope', 'SELECTED_BRANCHES')
                    ->whereIn('id', function ($subQuery) use ($branchCandidates): void {
                        $subQuery->select('employee_id')
                            ->from('employee_allowed_locations')
                            ->whereIn('location_id', $branchCandidates);
                    })
                    ->count();
            }
        }

        Log::info($context, [
            'current_branch_id' => $currentBranchId,
            'employee_scope' => $employeeScope,
            'branch_assigned_total' => $branchAssignedTotal,
            'global_permission_total' => $globalPermissionTotal,
            'selected_permission_total' => $selectedPermissionTotal,
            'returned_total' => (clone $query)->count(),
        ]);
    }

    public function logAllowedCandidates(
        string $context,
        ?int $currentBranchId,
        ?string $biometricType = null,
        string $employeeScope = 'active'
    ): void {
        $query = $this->getAllowedCandidatesQuery($currentBranchId, $biometricType, $employeeScope);

        $branchAssignedTotal = null;
        $globalPermissionTotal = 0;
        $selectedPermissionTotal = 0;
        $branchCandidates = $this->resolveLocationCandidates($currentBranchId);

        if ($currentBranchId !== null) {
            $branchAssignedTotal = (clone $query)->whereIn(
                'employee_id',
                Employee::query()
                    ->select('id')
                    ->whereIn('status', $this->resolveEmployeeStatuses($employeeScope))
                    ->whereIn('base_location_id', $branchCandidates)
            )->count();

            if ($this->hasGlobalBranchFlag()) {
                $globalPermissionTotal = (clone $query)->whereIn(
                    'employee_id',
                    Employee::query()
                        ->select('id')
                        ->whereIn('status', $this->resolveEmployeeStatuses($employeeScope))
                        ->where('can_check_all_branches', true)
                )->count();
            }

            if ($this->hasCheckScopeColumn() && $this->hasSelectedLocationsTable()) {
                $selectedPermissionTotal = (clone $query)->whereIn(
                    'employee_id',
                    Employee::query()
                        ->select('id')
                        ->whereIn('status', $this->resolveEmployeeStatuses($employeeScope))
                        ->where('check_scope', 'SELECTED_BRANCHES')
                        ->whereIn('id', function ($subQuery) use ($branchCandidates): void {
                            $subQuery->select('employee_id')
                                ->from('employee_allowed_locations')
                                ->whereIn('location_id', $branchCandidates);
                        })
                )->count();
            }
        }

        Log::info($context, [
            'current_branch_id' => $currentBranchId,
            'employee_scope' => $employeeScope,
            'biometric_type' => $this->normalizeBiometricType($biometricType) ?? 'ALL',
            'branch_assigned_total' => $branchAssignedTotal,
            'global_permission_total' => $globalPermissionTotal,
            'selected_permission_total' => $selectedPermissionTotal,
            'returned_total' => (clone $query)->count(),
        ]);
    }

    private function applyEmployeeScope(Builder $query, ?int $currentBranchId, string $employeeScope): void
    {
        $statuses = $this->resolveEmployeeStatuses($employeeScope);

        if (! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        if ($currentBranchId === null) {
            return;
        }

        $branchCandidates = $this->resolveLocationCandidates($currentBranchId);

        if (! $this->hasCheckScopeColumn()) {
            if (! $this->hasGlobalBranchFlag()) {
                $this->applyBaseLocationFilter($query, $branchCandidates);

                return;
            }

            $query->where(function (Builder $allowed) use ($branchCandidates): void {
                $allowed->where(function (Builder $baseLocationQuery) use ($branchCandidates): void {
                    $this->applyBaseLocationFilter($baseLocationQuery, $branchCandidates);
                })
                    ->orWhere('can_check_all_branches', true);
            });

            return;
        }

        $query->where(function (Builder $allowed) use ($branchCandidates): void {
            $allowed->where(function (Builder $homeOnly) use ($branchCandidates): void {
                $homeOnly->where('check_scope', 'HOME_ONLY')
                    ->where(function (Builder $baseLocationQuery) use ($branchCandidates): void {
                        $this->applyBaseLocationFilter($baseLocationQuery, $branchCandidates);
                    });
            });

            $allowed->orWhere('check_scope', 'ANY_BRANCH');

            if ($this->hasSelectedLocationsTable()) {
                $allowed->orWhere(function (Builder $selected) use ($branchCandidates): void {
                    $selected->where('check_scope', 'SELECTED_BRANCHES')
                        ->whereIn('id', function ($subQuery) use ($branchCandidates): void {
                            $subQuery->select('employee_id')
                                ->from('employee_allowed_locations')
                                ->whereIn('location_id', $branchCandidates);
                        });
                });
            }

            if ($this->hasGlobalBranchFlag()) {
                $allowed->orWhere(function (Builder $legacy) use ($branchCandidates): void {
                    $legacy->where(function (Builder $legacyScope): void {
                        $legacyScope->whereNull('check_scope')
                            ->orWhere('check_scope', '');
                    })->where(function (Builder $legacyInner) use ($branchCandidates): void {
                        $legacyInner->where(function (Builder $baseLocationQuery) use ($branchCandidates): void {
                            $this->applyBaseLocationFilter($baseLocationQuery, $branchCandidates);
                        })
                            ->orWhere('can_check_all_branches', true);
                    });
                });
            } else {
                $allowed->orWhere(function (Builder $legacy) use ($branchCandidates): void {
                    $legacy->where(function (Builder $legacyScope): void {
                        $legacyScope->whereNull('check_scope')
                            ->orWhere('check_scope', '');
                    })->where(function (Builder $baseLocationQuery) use ($branchCandidates): void {
                        $this->applyBaseLocationFilter($baseLocationQuery, $branchCandidates);
                    });
                });
            }
        });
    }

    private function applyBiometricTypeScope(Builder $query, ?string $biometricType): void
    {
        $normalizedType = $this->normalizeBiometricType($biometricType);

        if ($normalizedType === null) {
            $query->where(function (Builder $candidateQuery): void {
                $candidateQuery->where(function (Builder $fingerprintQuery): void {
                    $fingerprintQuery->where('enrolment_type', EmployeeFingerprint::TYPE_FINGERPRINT)
                        ->orWhereNull('enrolment_type');
                });

                $candidateQuery->orWhere(function (Builder $faceQuery): void {
                    $faceQuery->where('enrolment_type', EmployeeFingerprint::TYPE_FACE);
                    $this->applyFaceAvailabilityScope($faceQuery);
                });
            });

            return;
        }

        if ($normalizedType === EmployeeFingerprint::TYPE_FACE) {
            $query->where('enrolment_type', EmployeeFingerprint::TYPE_FACE);
            $this->applyFaceAvailabilityScope($query);

            return;
        }

        $query->where(function (Builder $typeQuery): void {
            $typeQuery->where('enrolment_type', EmployeeFingerprint::TYPE_FINGERPRINT)
                ->orWhereNull('enrolment_type');
        });
    }

    private function normalizeBiometricType(?string $biometricType): ?string
    {
        $normalized = strtoupper(trim((string) $biometricType));

        if ($normalized === '' || $normalized === 'ALL') {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    private function employeeRelationSelect(): array
    {
        $columns = ['id', 'base_location_id', 'status'];

        if ($this->hasGlobalBranchFlag()) {
            $columns[] = 'can_check_all_branches';
        }

        if ($this->hasCheckScopeColumn()) {
            $columns[] = 'check_scope';
        }

        foreach ([
            'has_face_enrollment',
            'face_status',
            'face_samples_count',
            'face_enabled',
            'face_template_version',
            'face_quality_score',
            'face_updated_at',
            'face_sync_ready',
        ] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $columns[] = $column;
            }
        }

        return [
            'employee' => function ($employee) use ($columns): void {
                $employee->select($columns);
            },
        ];
    }

    /**
     * @param  array<int, int>  $branchCandidates
     */
    private function applyBaseLocationFilter(Builder $query, array $branchCandidates): void
    {
        if (empty($branchCandidates)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (count($branchCandidates) === 1) {
            $query->where('base_location_id', $branchCandidates[0]);

            return;
        }

        $query->whereIn('base_location_id', $branchCandidates);
    }

    /**
     * @return array<int, int>
     */
    private function resolveLocationCandidates(?int $currentBranchId): array
    {
        if ($currentBranchId === null) {
            return [];
        }

        $candidates = [$currentBranchId];
        if (! Schema::hasTable('locations')) {
            return $candidates;
        }

        $locationById = DB::table('locations')
            ->where('id', $currentBranchId)
            ->first(['id', 'fortia_location_id']);

        if ($locationById && isset($locationById->fortia_location_id) && is_numeric($locationById->fortia_location_id)) {
            $candidates[] = (int) $locationById->fortia_location_id;
        }

        if (Schema::hasColumn('locations', 'fortia_location_id')) {
            $locationByFortia = DB::table('locations')
                ->where('fortia_location_id', $currentBranchId)
                ->first(['id', 'fortia_location_id']);

            if ($locationByFortia && isset($locationByFortia->id) && is_numeric($locationByFortia->id)) {
                $candidates[] = (int) $locationByFortia->id;
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            static fn ($value): bool => is_numeric($value) && (int) $value > 0
        )));
    }

    private function hasGlobalBranchFlag(): bool
    {
        return Schema::hasColumn('employees', 'can_check_all_branches');
    }

    private function hasCheckScopeColumn(): bool
    {
        return Schema::hasColumn('employees', 'check_scope');
    }

    private function hasSelectedLocationsTable(): bool
    {
        return Schema::hasTable('employee_allowed_locations');
    }

    private function applyFaceAvailabilityScope(Builder $query): void
    {
        if (! Schema::hasColumn('employees', 'has_face_enrollment')) {
            return;
        }

        $query->whereIn('employee_id', $this->faceReadyEmployeesQuery()->select('id'));
    }

    private function faceReadyEmployeesQuery(): Builder
    {
        $query = Employee::query()->where('has_face_enrollment', true);

        if (Schema::hasColumn('employees', 'face_enabled')) {
            $query->where('face_enabled', true);
        }

        if (Schema::hasColumn('employees', 'face_status')) {
            $query->whereIn('face_status', self::FACE_SYNC_READY_STATUSES);
        }

        if (Schema::hasColumn('employees', 'face_samples_count')) {
            $query->where('face_samples_count', '>', 0);
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function resolveEmployeeStatuses(string $employeeScope): array
    {
        $normalizedScope = strtolower(trim($employeeScope));

        return match ($normalizedScope) {
            'active' => self::ACTIVE_EMPLOYEE_STATUSES,
            'inactive' => self::INACTIVE_EMPLOYEE_STATUSES,
            default => [],
        };
    }
}
