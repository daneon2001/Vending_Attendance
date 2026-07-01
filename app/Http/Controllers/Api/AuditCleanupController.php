<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditCleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditCleanupController extends Controller
{
    public function dashboard(AuditCleanupService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->dashboard(),
        ]);
    }

    public function updateSettings(Request $request, AuditCleanupService $service): JsonResponse
    {
        $validated = $request->validate([
            'critical_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'important_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'noise_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'batch_size' => ['required', 'integer', 'min:100', 'max:50000'],
            'heartbeat_log_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'optimize_min_deleted_mb' => ['required', 'integer', 'min:1', 'max:102400'],
        ]);

        return response()->json([
            'message' => 'Configuracion de limpieza actualizada.',
            'data' => $service->updateSettings($validated, $request->user()),
        ]);
    }

    public function preview(Request $request, AuditCleanupService $service): JsonResponse
    {
        $validated = $this->validateCleanupPayload($request);

        return response()->json([
            'data' => $service->preview($validated),
        ]);
    }

    public function execute(Request $request, AuditCleanupService $service): JsonResponse
    {
        $validated = $this->validateCleanupPayload($request);

        return response()->json([
            'message' => 'Limpieza de bitacora completada.',
            'data' => $service->execute($validated, $request->user(), 'manual'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCleanupPayload(Request $request): array
    {
        return $request->validate([
            'settings' => ['nullable', 'array'],
            'settings.critical_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'settings.important_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'settings.noise_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'settings.batch_size' => ['nullable', 'integer', 'min:100', 'max:50000'],
            'settings.heartbeat_log_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'settings.optimize_min_deleted_mb' => ['nullable', 'integer', 'min:1', 'max:102400'],
            'selection' => ['nullable', 'array'],
            'selection.delete_heartbeats' => ['nullable', 'boolean'],
            'selection.delete_sync' => ['nullable', 'boolean'],
            'selection.delete_old_logins' => ['nullable', 'boolean'],
            'selection.delete_noise' => ['nullable', 'boolean'],
            'selection.delete_except_critical' => ['nullable', 'boolean'],
            'selection.before_date' => ['nullable', 'date_format:Y-m-d'],
            'selection.target_free_mb' => ['nullable', 'integer', 'min:0', 'max:102400'],
            'selection.optimize' => ['nullable', 'boolean'],
            'selection.simulate' => ['nullable', 'boolean'],
        ]);
    }
}
