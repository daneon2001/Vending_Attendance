<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeCompactResource;
use App\Services\Employees\EmployeeCatalogQueryService;
use App\Services\Fortia\FortiaEmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminEmployeeController extends Controller
{
    public function index(
        Request $request,
        FortiaEmployeeService $fortiaService,
        EmployeeCatalogQueryService $catalogQueryService
    ): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:120'],
            'unit_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'string', 'max:32'],
            'company_id' => ['nullable', 'integer'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['A', 'B', 'active', 'inactive', 'ACTIVE', 'INACTIVE'])],
            'fingerprint' => ['nullable', 'string', Rule::in(['with', 'without'])],
            'face' => ['nullable', 'string', Rule::in(['with', 'without'])],
            'sync_ready' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', Rule::in(['name', 'updated_at'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $sortBy = $validated['sort_by'] ?? 'name';
        $sortDir = strtolower((string) ($validated['sort_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

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
            'rfc',
            'curp',
            'has_fingerprint',
            'can_check_all_branches',
            'check_scope',
            'updated_at',
        ];

        foreach (['employee_code', 'code', 'clave_empleado'] as $column) {
            if (Schema::hasColumn('employees', $column) && ! in_array($column, $select, true)) {
                $select[] = $column;
            }
        }

        foreach ([
            'has_face_enrollment',
            'face_status',
            'face_samples_count',
            'face_template_version',
            'face_updated_at',
            'face_enabled',
            'face_quality_score',
            'face_meta',
        ] as $column) {
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

        $query = $catalogQueryService->buildFilteredEmployeeQuery($validated, $select, $with);

        if ($sortBy === 'updated_at') {
            $query->orderBy('updated_at', $sortDir)->orderBy('id');
        } else {
            $query->orderBy('full_name', $sortDir)->orderBy('id');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page)->withQueryString();

        return response()->json([
            'ok' => true,
            'data' => EmployeeCompactResource::collection($paginator->getCollection())->resolve(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'sync' => $fortiaService->describeMode(),
        ]);
    }
}
