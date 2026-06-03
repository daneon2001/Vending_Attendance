<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ExternalEmployeeController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'lookup_by' => ['nullable', 'string', Rule::in(['id', 'internal_id', 'fortia', 'fortia_employee_id'])],
        ]);

        $lookupBy = $this->resolveLookupBy($validated['lookup_by'] ?? null);
        $employee = $this->findEmployee($id, $lookupBy);

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Empleado no encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformEmployee($employee),
        ]);
    }

    private function findEmployee(string $id, string $lookupBy): ?Employee
    {
        $query = Employee::query()->select($this->selectColumns());

        if ($lookupBy === 'fortia_employee_id') {
            return $query->where('fortia_employee_id', $id)->first();
        }

        if (! ctype_digit($id)) {
            return null;
        }

        return $query->whereKey((int) $id)->first();
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

    private function resolveLookupBy(?string $lookupBy): string
    {
        return in_array($lookupBy, ['fortia', 'fortia_employee_id'], true)
            ? 'fortia_employee_id'
            : 'id';
    }
}
