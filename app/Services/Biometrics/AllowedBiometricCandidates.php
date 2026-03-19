<?php

namespace App\Services\Biometrics;

use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use Illuminate\Database\Eloquent\Builder;
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
        $hasGlobalFlag = $this->hasGlobalBranchFlag();

        Log::info($context, [
            'current_branch_id' => $currentBranchId,
            'employee_scope' => $employeeScope,
            'branch_assigned_total' => $currentBranchId !== null
                ? (clone $query)->where('base_location_id', $currentBranchId)->count()
                : null,
            'global_permission_total' => $hasGlobalFlag
                ? (clone $query)->where('can_check_all_branches', true)->count()
                : 0,
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
        $hasGlobalFlag = $this->hasGlobalBranchFlag();

        Log::info($context, [
            'current_branch_id' => $currentBranchId,
            'employee_scope' => $employeeScope,
            'biometric_type' => $this->normalizeBiometricType($biometricType) ?? 'ALL',
            'branch_assigned_total' => $currentBranchId !== null
                ? (clone $query)->whereIn(
                    'employee_id',
                    Employee::query()
                        ->select('id')
                        ->where('base_location_id', $currentBranchId)
                )->count()
                : null,
            'global_permission_total' => $hasGlobalFlag
                ? (clone $query)->whereIn(
                    'employee_id',
                    Employee::query()
                        ->select('id')
                        ->where('can_check_all_branches', true)
                )->count()
                : 0,
            'returned_total' => (clone $query)->count(),
        ]);
    }

    private function applyEmployeeScope(Builder $query, ?int $currentBranchId, string $employeeScope): void
    {
        $normalizedScope = strtolower(trim($employeeScope));

        if ($normalizedScope === 'active') {
            $query->whereIn('status', self::ACTIVE_EMPLOYEE_STATUSES);
        } elseif ($normalizedScope === 'inactive') {
            $query->whereIn('status', self::INACTIVE_EMPLOYEE_STATUSES);
        }

        if ($currentBranchId === null) {
            return;
        }

        if (! $this->hasGlobalBranchFlag()) {
            $query->where('base_location_id', $currentBranchId);

            return;
        }

        $query->where(function (Builder $allowed) use ($currentBranchId): void {
            $allowed->where('base_location_id', $currentBranchId)
                ->orWhere('can_check_all_branches', true);
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

        foreach ([
            'has_face_enrollment',
            'face_status',
            'face_samples_count',
            'face_enabled',
            'face_template_version',
            'face_quality_score',
            'face_updated_at',
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

    private function hasGlobalBranchFlag(): bool
    {
        return Schema::hasColumn('employees', 'can_check_all_branches');
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
}
