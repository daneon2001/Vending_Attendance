<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExternalEmployeeController extends Controller
{
    public function show(string $id): JsonResponse
    {
        if (! ctype_digit($id)) {
            return $this->notFoundResponse();
        }

        $query = Employee::query()->select($this->selectColumns());
        $this->applyPositionJoin($query);

        $employee = $query->whereKey((int) $id)->first();

        return $this->buildEmployeeResponse($employee);
    }

    public function showByFortia(string $fortiaEmployeeId): JsonResponse
    {
        if (! ctype_digit($fortiaEmployeeId)) {
            return $this->notFoundResponse();
        }

        $query = Employee::query()->select($this->selectColumns());
        $this->applyPositionJoin($query);

        $employee = $query->where('fortia_employee_id', $fortiaEmployeeId)->first();

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
            'employees.id',
            'employees.fortia_employee_id',
            'employees.name',
            'employees.last_name',
            'employees.second_last_name',
            'employees.full_name',
            'employees.status',
            'employees.company_id',
            'employees.company_name',
            'employees.base_location_id',
            'employees.base_location_name',
            'employees.department_id',
            'employees.department_name',
            'employees.has_fingerprint',
            'employees.updated_at',
        ];

        foreach (['employee_code', 'can_check_all_branches', 'check_scope', 'has_face_enrollment', 'face_enabled'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $columns[] = 'employees.'.$column;
            }
        }

        if (Schema::hasTable('employee_import_metadata')) {
            $columns[] = 'employee_import_metadata.payload as employee_import_metadata_payload';
        }

        if (! Schema::hasTable('employee_details') || ! Schema::hasTable('puestos')) {
            $columns[] = DB::raw('NULL as position_id');
            $columns[] = DB::raw('NULL as position_code');
            $columns[] = DB::raw('NULL as position_name');
        }

        return $columns;
    }

    private function applyPositionJoin(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if (Schema::hasTable('employee_details') && Schema::hasTable('puestos')) {
            $query->leftJoin('employee_details', 'employee_details.employee_id', '=', 'employees.id')
                ->leftJoin('puestos', 'puestos.id', '=', 'employee_details.puesto_id')
                ->addSelect([
                    'puestos.id as position_id',
                    'puestos.cla_puesto as position_code',
                    'puestos.nom_puesto as position_name',
                ]);
        }

        if (Schema::hasTable('employee_import_metadata')) {
            $query->leftJoin(
                'employee_import_metadata',
                'employee_import_metadata.employee_id',
                '=',
                'employees.id'
            );
        }
    }

    private function transformEmployee(Employee $employee): array
    {
        $positionId = $employee->getAttribute('position_id');
        $positionCode = $employee->getAttribute('position_code');
        $positionName = $employee->getAttribute('position_name');
        $importMetadata = json_decode((string) $employee->getAttribute('employee_import_metadata_payload'), true);
        $importMetadata = is_array($importMetadata) ? $importMetadata : [];

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
            'position_id' => $positionId !== null ? (int) $positionId : null,
            'position_code' => $positionCode !== null ? (string) $positionCode : null,
            'position_name' => $positionName !== null ? (string) $positionName : null,
            'position' => $positionId !== null
                ? [
                    'id' => (int) $positionId,
                    'code' => $positionCode !== null ? (string) $positionCode : null,
                    'name' => $positionName !== null ? (string) $positionName : null,
                ]
                : null,
            'can_check_all_branches' => (bool) ($employee->can_check_all_branches ?? false),
            'check_scope' => (string) ($employee->resolved_check_scope ?? $employee->check_scope ?? 'HOME_ONLY'),
            'has_fingerprint' => (bool) $employee->has_fingerprint,
            'has_face_enrollment' => (bool) ($employee->has_face_enrollment ?? false),
            'face_enabled' => (bool) ($employee->face_enabled ?? false),
            'fecha_alta' => $this->metadataDate($importMetadata, 'fecha_ing'),
            'fecha_baja' => $this->metadataDate($importMetadata, 'fecha_baja'),
            'updated_at' => $this->formatUpdatedAt($employee->updated_at),
        ];
    }

    private function metadataDate(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
