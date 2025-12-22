<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Services\Audit\AuditLogger;
use App\Services\FortiaMock\FortiaMockSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()->with('fingerprints');

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

        $employees = $query->orderBy('full_name')->paginate(15);

        return response()->json($employees);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json([
            'employee' => $employee->load('fingerprints'),
        ]);
    }

    public function syncFromFortia(): JsonResponse
    {
        // TODO: llamar FortiaEmployeeService::syncEmployees()
        return response()->json([
            'message' => 'Sync from Fortia scheduled/TODO',
        ], 202);
    }

    public function syncFortiaMock(Request $request, FortiaMockSyncService $syncService): JsonResponse
    {
        $filters = [];
        if ($request->filled('company_id')) {
            $filters['company_id'] = (int) $request->input('company_id');
        }

        $summary = $syncService->syncIncremental($filters);

        AuditLogger::log(
            'employees.sync_mock',
            null,
            'Sincronización con Fortia Mock',
            $summary
        );

        return response()->json([
            'created_count' => $summary['new'] ?? 0,
            'updated_count' => $summary['updated'] ?? 0,
            'unchanged_count' => $summary['unchanged'] ?? 0,
            'status_changed_count' => $summary['status_changed'] ?? 0,
            'status_changed' => $summary['changed'] ?? [],
        ]);
    }

    public function updateStatus(Employee $employee, Request $request): JsonResponse
    {
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

        return response()->json($employee->refresh());
    }

    public function deleteFingerprint(Employee $employee, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clock_id' => ['nullable', 'integer'],
        ]);

        $fingerprintsQuery = $employee->fingerprints();
        if (! empty($validated['clock_id'])) {
            $fingerprintsQuery->where('clock_id', $validated['clock_id']);
        }

        $fingerprints = $fingerprintsQuery->get();

        if ($fingerprints->isEmpty()) {
            return response()->json([
                'message' => 'No se encontraron huellas para borrar.',
            ], 404);
        }

        $affected = 0;
        foreach ($fingerprints as $fingerprint) {
            if ($fingerprint->status === 'deleted') {
                continue;
            }

            $fingerprint->status = 'pending_delete'; // o 'deleted' si el on-premise responde
            $fingerprint->deleted_at = now();
            $fingerprint->save();
            $affected++;
        }

        // TODO: llamar aquí al servicio on-premise que elimina la plantilla de huella
        // Ejemplo:
        // app(OnPremiseBiometricsService::class)->deleteFingerprint($employee->id, $validated['clock_id'] ?? null);

        $employee->refreshFingerprintFlag();

        AuditLogger::log(
            'employees.fingerprint_deleted',
            $employee,
            'Borrado de huella',
            [
                'clock_id' => $validated['clock_id'] ?? null,
                'affected' => $affected,
            ]
        );

        return response()->json([
            'message' => 'Borrado de huella en proceso.',
            'affected' => $affected,
            'has_fingerprint' => $employee->has_fingerprint,
            'fingerprint_status' => $employee->fingerprint_status,
        ]);
    }

    public function storeFingerprint(Employee $employee, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clock_id' => ['required', 'integer', 'exists:clocks,id'],
        ]);

        $fingerprint = EmployeeFingerprint::create([
            'employee_id' => $employee->id,
            'clock_id' => $validated['clock_id'],
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $employee->refreshFingerprintFlag();

        AuditLogger::log(
            'employees.fingerprint_registered',
            $employee,
            'Huella registrada',
            [
                'clock_id' => $validated['clock_id'],
                'fingerprint_id' => $fingerprint->id,
            ]
        );

        return response()->json([
            'message' => 'Huella registrada correctamente.',
            'fingerprint' => $fingerprint,
        ], 201);
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower($status);

        return in_array($status, ['active', 'a'], true) ? 'A' : 'B';
    }
}
