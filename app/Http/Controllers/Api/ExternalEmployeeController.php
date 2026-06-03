<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class ExternalEmployeeController extends Controller
{
    public function show(string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return $this->notFoundResponse();
        }

        $employee = Employee::query()
            ->select($this->selectColumns())
            ->whereKey((int) $id)
            ->first();

        return $this->buildEmployeeResponse($employee);
    }

    public function showByFortia(string $fortiaEmployeeId): JsonResponse
    {
        if (! ctype_digit($fortiaEmployeeId)) {
            return $this->notFoundResponse();
        }

        $employee = Employee::query()
            ->select($this->selectColumns())
            ->where('fortia_employee_id', $fortiaEmployeeId)
            ->first();

        return $this->buildEmployeeResponse($employee);
    }

    private function buildEmployeeResponse(?Employee $employee): JsonResponse
    {
        if (! $employee) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformEmployee($employee),
        ]);
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Empleado no encontrado.',
        ], 404);
    }

    private function selectColumns(): array
    {
        $columns = [
            'id',
            'fortia_employee_id',
            'name',
            'last_name',
            'second_last_name',
            'full_name',
            'status',
            'company_id',
            'company_name',
            'base_location_id',
            'base_location_name',
            'department_id',
            'department_name',
            'has_fingerprint',
            'updated_at',
        ];

        foreach (['employee_code', 'can_check_all_branches', 'check_scope', 'has_face_enrollment', 'face_enabled'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function transformEmployee(Employee $employee): array
    {
        return [
            'id' => (int) $employee->id,
            'fortia_employee_id' => $employee->fortia_employee_id !== null ? (string) $employee->fortia_employee_id : null,
            'employee_code' => $this->resolveEmployeeCode($employee),
            'full_name' => $employee->full_name,
            'name' => $employee->name,
            'last_name' => $employee->last_name,
            'second_last_name' => $employee->second_last_name,
            'status' => $employee->status,
            'company_id' => $employee->company_id !== null ? (int) $employee->company_id : null,
            'company_name' => $employee->company_name,
            'base_location_id' => $employee->base_location_id !== null ? (int) $employee->base_location_id : null,
            'base_location_name' => $employee->base_location_name,
            'department_id' => $employee->department_id !== null ? (int) $employee->department_id : null,
            'department_name' => $employee->department_name,
            'can_check_all_branches' => (bool) ($employee->can_check_all_branches ?? false),
            'check_scope' => (string) ($employee->resolved_check_scope ?? $employee->check_scope ?? 'HOME_ONLY'),
            'has_fingerprint' => (bool) $employee->has_fingerprint,
            'has_face_enrollment' => (bool) ($employee->has_face_enrollment ?? false),
            'face_enabled' => (bool) ($employee->face_enabled ?? false),
            'updated_at' => $this->formatUpdatedAt($employee->updated_at),
        ];
    }

    private function resolveEmployeeCode(Employee $employee): ?string
    {
        if (Schema::hasColumn('employees', 'employee_code')) {
            $value = $employee->getAttribute('employee_code');

            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function formatUpdatedAt(mixed $updatedAt): ?string
    {
        if (! $updatedAt instanceof CarbonInterface) {
            return null;
        }

        return $updatedAt
            ->copy()
            ->setTimezone((string) config('operations.timezone', 'America/Mexico_City'))
            ->toIso8601String();
    }

}
