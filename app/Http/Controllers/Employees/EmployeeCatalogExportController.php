<?php

namespace App\Http\Controllers\Employees;

use App\Exports\EmployeesCatalogExport;
use App\Http\Controllers\Controller;
use App\Services\Employees\EmployeeCatalogQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeCatalogExportController extends Controller
{
    public function __invoke(Request $request, EmployeeCatalogQueryService $catalogQueryService): BinaryFileResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'unit_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'string', 'max:32'],
            'company_id' => ['nullable', 'integer'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['A', 'B', 'active', 'inactive', 'ACTIVE', 'INACTIVE'])],
            'fingerprint' => ['nullable', 'string', Rule::in(['with', 'without'])],
            'face' => ['nullable', 'string', Rule::in(['with', 'without'])],
            'sync_ready' => ['nullable', 'boolean'],
        ]);

        $select = [
            'id',
            'fortia_employee_id',
            'company_id',
            'company_name',
            'name',
            'last_name',
            'second_last_name',
            'full_name',
            'base_location_id',
            'base_location_name',
            'status',
            'has_fingerprint',
            'can_check_all_branches',
            'check_scope',
            'created_at',
            'updated_at',
        ];

        foreach (['employee_code', 'code', 'clave_empleado'] as $column) {
            if (Schema::hasColumn('employees', $column) && ! in_array($column, $select, true)) {
                $select[] = $column;
            }
        }

        foreach (['has_face_enrollment'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                $select[] = $column;
            }
        }

        $with = [
            'baseLocation:id,name,fortia_location_id,code',
        ];

        if (Schema::hasTable('employee_allowed_locations')) {
            $with[] = 'allowedLocations:id,name,code';
        }

        $query = $catalogQueryService->buildFilteredEmployeeQuery($filters, $select, $with)
            ->orderBy('full_name')
            ->orderBy('id');

        $filename = sprintf(
            'Catalogo_Empleados_%s.xlsx',
            now()->timezone(config('app.timezone', 'America/Mexico_City'))->format('Ymd_Hi')
        );

        return Excel::download(new EmployeesCatalogExport($query), $filename);
    }
}
