<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeCompactResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminEmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:120'],
            'unit_id' => ['nullable', 'integer'],
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
            'name',
            'last_name',
            'second_last_name',
            'full_name',
            'base_location_id',
            'base_location_name',
            'status',
            'has_fingerprint',
            'updated_at',
        ];

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

        $query = Employee::query()
            ->select($select)
            ->with(['unit:id,name']);

        if (! empty($validated['q'])) {
            $needle = trim((string) $validated['q']);
            $query->where(function ($builder) use ($needle) {
                $builder->where('full_name', 'like', "%{$needle}%")
                    ->orWhere('name', 'like', "%{$needle}%")
                    ->orWhere('last_name', 'like', "%{$needle}%")
                    ->orWhere('fortia_employee_id', 'like', "%{$needle}%");
            });
        }

        if (! empty($validated['unit_id'])) {
            $query->where('base_location_id', (int) $validated['unit_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $this->normalizeStatusFilter((string) $validated['status']));
        }

        if (! empty($validated['fingerprint'])) {
            $query->where('has_fingerprint', $validated['fingerprint'] === 'with');
        }

        if (! empty($validated['face']) && Schema::hasColumn('employees', 'has_face_enrollment')) {
            $query->where('has_face_enrollment', $validated['face'] === 'with');
        }

        if (! empty($validated['sync_ready']) && Schema::hasColumn('employees', 'has_face_enrollment')) {
            $query->faceSyncReady();
        }

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
        ]);
    }

    private function normalizeStatusFilter(string $status): string
    {
        return in_array(strtolower($status), ['active', 'a'], true) ? 'A' : 'B';
    }
}
