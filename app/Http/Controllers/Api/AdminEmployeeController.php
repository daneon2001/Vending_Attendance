<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeCompactResource;
use App\Models\Employee;
use App\Models\Location;
use App\Services\Fortia\FortiaEmployeeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminEmployeeController extends Controller
{
    public function index(Request $request, FortiaEmployeeService $fortiaService): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:120'],
            'unit_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
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
            $with[] = 'allowedLocations:id,name';
        }

        $query = Employee::query()
            ->select($select)
            ->with($with);

        $this->applyEmployeeSearch($query, $validated['q'] ?? null);

        $locationId = $validated['location_id'] ?? $validated['unit_id'] ?? null;
        if (! empty($locationId)) {
            $resolvedBaseLocationId = $this->resolveEmployeeBaseLocationId((int) $locationId);

            if ($resolvedBaseLocationId !== null) {
                $query->where('base_location_id', $resolvedBaseLocationId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($validated['company_id'])) {
            $query->where('company_id', (int) $validated['company_id']);
        }

        if (! empty($validated['company_name'])) {
            $query->where('company_name', 'like', '%'.trim((string) $validated['company_name']).'%');
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
            'sync' => $fortiaService->describeMode(),
        ]);
    }

    private function normalizeStatusFilter(string $status): string
    {
        return in_array(strtolower($status), ['active', 'a'], true) ? 'A' : 'B';
    }

    private function applyEmployeeSearch(Builder $query, ?string $search): void
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $search));
        if (! is_string($normalized) || $normalized === '') {
            return;
        }

        $normalized = mb_substr($normalized, 0, 120);
        $tokens = collect(explode(' ', $normalized))
            ->map(static fn ($token) => trim($token))
            ->filter()
            ->take(8)
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $searchableColumns = $this->employeeSearchableColumns();
        if ($searchableColumns === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($tokens, $searchableColumns): void {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $tokenQuery) use ($token, $searchableColumns): void {
                    $isNumericToken = preg_match('/^\d+$/', $token) === 1;

                    foreach ($searchableColumns as $column) {
                        if ($isNumericToken && in_array($column, ['id', 'fortia_employee_id', 'employee_code'], true)) {
                            $tokenQuery->orWhere($column, $token)
                                ->orWhere($column, 'like', '%'.$token.'%');
                        } else {
                            $tokenQuery->orWhere($column, 'like', '%'.$token.'%');
                        }
                    }
                });
            }
        });
    }

    /**
     * @return list<string>
     */
    private function employeeSearchableColumns(): array
    {
        $columns = [
            'id',
            'full_name',
            'name',
            'last_name',
            'second_last_name',
            'employee_code',
            'fortia_employee_id',
            'rfc',
            'curp',
            'company_name',
            'base_location_name',
        ];

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('employees', $column)
        ));
    }

    private function resolveEmployeeBaseLocationId(int $locationFilter): ?int
    {
        if ($locationFilter <= 0) {
            return null;
        }

        $locationQuery = Location::query()
            ->select('id', 'fortia_location_id', 'code')
            ->whereKey($locationFilter);

        if (Schema::hasColumn('locations', 'fortia_location_id')) {
            $locationQuery->orWhere('fortia_location_id', $locationFilter);
        }

        if (Schema::hasColumn('locations', 'code')) {
            $locationQuery->orWhere('code', (string) $locationFilter);
        }

        $location = $locationQuery->first();
        if (! $location) {
            return null;
        }

        if (is_numeric($location->fortia_location_id)) {
            return (int) $location->fortia_location_id;
        }

        if (is_numeric($location->code)) {
            return (int) $location->code;
        }

        return (int) $locationFilter;
    }
}
