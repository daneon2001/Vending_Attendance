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
            return;
        }

        $query->where(function (Builder $typeQuery) use ($normalizedType): void {
            $typeQuery->where('enrolment_type', $normalizedType);

            if ($normalizedType === 'FINGERPRINT') {
                $typeQuery->orWhereNull('enrolment_type');
            }
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
}
