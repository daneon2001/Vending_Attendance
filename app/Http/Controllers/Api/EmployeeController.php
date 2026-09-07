<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeCompactResource;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Services\Audit\AuditLogger;
use App\Services\Fortia\FortiaEmployeeService;
use App\Services\FortiaMock\FortiaMockSyncService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $includes = $this->resolveIncludes($request);
        $includeFingerprints = in_array('fingerprints', $includes, true);

        $query = Employee::query();

        if ($includeFingerprints) {
            $query->with([
                'fingerprints' => function (HasMany $fingerprints): void {
                    $fingerprints->select([
                        'id',
                        'employee_id',
                        'enrolment_type',
                        'status',
                        'created_at',
                    ]);

                    if (Schema::hasColumn('employee_fingerprints', 'quality')) {
                        $fingerprints->addSelect('quality');
                    }
                },
            ]);
        }

        if ($request->filled('status')) {
            $status = $this->normalizeStatus($request->string('status'));
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%")
                    ->orWhere('imss_number', 'like', "%{$search}%")
                    ->orWhere('curp', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(5, min($perPage, 100));
        $employees = $query->orderBy('full_name')->paginate($perPage)->withQueryString();

        $data = collect($employees->items())
            ->map(fn (Employee $employee) => $this->transformEmployeeListItem($employee, $includeFingerprints))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
                'from' => $employees->firstItem(),
                'to' => $employees->lastItem(),
            ],
        ]);
    }

    public function syncFromFortia(
        Request $request,
        FortiaEmployeeService $fortiaService,
        FortiaMockSyncService $mockSyncService
    ): JsonResponse {
        $filters = [];
        if ($request->filled('company_id')) {
            $filters['company_id'] = (int) $request->input('company_id');
        }

        $syncMode = $fortiaService->describeMode();

        try {
            $summary = $fortiaService->usingMockMode()
                ? $mockSyncService->syncIncremental($filters)
                : $fortiaService->syncEmployees($filters);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'sync' => $syncMode,
            ], 503);
        }

        AuditLogger::log(
            'employees.sync',
            null,
            'Sincronizacion de empleados',
            array_merge($summary, [
                'action' => 'employees.sync',
                'entity' => 'employees',
                'reason' => 'sync_completed',
                'sync' => $syncMode,
            ])
        );

        return response()->json([
            'created_count' => $summary['new'] ?? 0,
            'updated_count' => $summary['updated'] ?? 0,
            'unchanged_count' => $summary['unchanged'] ?? 0,
            'status_changed_count' => $summary['status_changed'] ?? 0,
            'status_changed' => $summary['changed'] ?? [],
            'sync' => $syncMode,
        ]);
    }

    public function updateStatus(Employee $employee, Request $request): JsonResponse
    {
        abort_if($employee->source === \App\Enums\Employees\EmployeeSource::FORTIA, 409, 'El estado de este empleado pertenece a Fortia.');
        $validated = $request->validate([
            'status' => ['required', Rule::in(['A', 'B', 'active', 'inactive'])],
        ]);

        $normalized = $this->normalizeStatus($validated['status']);
        $before = $employee->status;
        $employee->update(['status' => $normalized]);

        AuditLogger::log(
            'employees.status_changed',
            $employee,
            'Cambio de estado de empleado',
            [
                'before' => $before,
                'after' => $normalized,
            ]
        );

        return response()->json(
            (new EmployeeCompactResource($employee->refresh()->loadMissing('baseLocation:id,name,fortia_location_id,code')))->resolve()
        );
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower($status);

        return in_array($status, ['active', 'a'], true) ? 'A' : 'B';
    }

    /**
     * @return array<int, string>
     */
    private function resolveIncludes(Request $request): array
    {
        $rawInclude = (string) $request->query('include', '');
        if (trim($rawInclude) === '') {
            return [];
        }

        $allowed = ['fingerprints'];

        return collect(explode(',', $rawInclude))
            ->map(fn (string $include) => strtolower(trim($include)))
            ->filter()
            ->unique()
            ->intersect($allowed)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEmployeeListItem(Employee $employee, bool $includeFingerprints): array
    {
        $data = $employee->toArray();

        if (! $includeFingerprints) {
            unset($data['fingerprints']);

            return $data;
        }

        $data['fingerprints'] = $employee->fingerprints
            ->map(fn (EmployeeFingerprint $fingerprint) => $this->transformFingerprintMetadata($fingerprint))
            ->values()
            ->all();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformFingerprintMetadata(EmployeeFingerprint $fingerprint): array
    {
        $quality = $fingerprint->quality ?? null;
        $enrolmentType = strtoupper(trim((string) ($fingerprint->enrolment_type ?? '')));

        return [
            'id' => (int) $fingerprint->id,
            'type' => $enrolmentType !== '' ? $enrolmentType : EmployeeFingerprint::TYPE_FINGERPRINT,
            'quality' => is_numeric($quality) ? (int) $quality : null,
            'created_at' => optional($fingerprint->created_at)->toISOString(),
        ];
    }
}
