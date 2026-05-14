<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FaceIdTemplateSyncRequest;
use App\Models\Employee;
use App\Models\EmployeeFaceTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FaceIdTemplateSyncController extends Controller
{
    public function sync(FaceIdTemplateSyncRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $templateHash = trim((string) $validated['template_hash']);

        Log::info('faceid.sync.received', [
            'employee_id' => $validated['employee_id'] ?? null,
            'fortia_employee_id' => $validated['fortia_employee_id'] ?? null,
            'template_hash' => substr($templateHash, 0, 12),
            'model_name' => $validated['model_name'] ?? null,
            'device_serial' => $validated['device_serial'] ?? null,
        ]);

        $employee = $this->resolveEmployee($validated);
        if (! $employee) {
            Log::warning('faceid.sync.failed', [
                'employee_id' => $validated['employee_id'] ?? null,
                'fortia_employee_id' => $validated['fortia_employee_id'] ?? null,
                'template_hash' => substr($templateHash, 0, 12),
                'reason' => 'employee_not_found',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Employee not found.',
            ], 404);
        }

        $employeeId = (int) $employee->getKey();
        $existingTemplate = EmployeeFaceTemplate::query()
            ->where('template_hash', $templateHash)
            ->first();

        if ($existingTemplate && (int) $existingTemplate->employee_id !== $employeeId) {
            Log::warning('faceid.sync.failed', [
                'employee_id' => $employeeId,
                'fortia_employee_id' => $validated['fortia_employee_id'] ?? null,
                'template_hash' => substr($templateHash, 0, 12),
                'reason' => 'template_hash_conflict',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Template hash already linked to another employee.',
            ], 409);
        }

        try {
            $template = DB::transaction(function () use ($validated, $employee, $employeeId, $existingTemplate): EmployeeFaceTemplate {
                EmployeeFaceTemplate::query()
                    ->where('employee_id', $employeeId)
                    ->where('is_active', true)
                    ->when($existingTemplate, fn ($query) => $query->where('id', '!=', $existingTemplate->getKey()))
                    ->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);

                $attributes = [
                    'employee_id' => $employeeId,
                    'fortia_employee_id' => $validated['fortia_employee_id'] ?? ($employee->fortia_employee_id !== null ? (string) $employee->fortia_employee_id : null),
                    'employee_code' => $validated['employee_code'] ?? null,
                    'template_hash' => trim((string) $validated['template_hash']),
                    'embedding_encrypted' => (string) $validated['embedding_encrypted'],
                    'quality_score' => isset($validated['quality_score']) ? (float) $validated['quality_score'] : null,
                    'model_name' => (string) $validated['model_name'],
                    'model_version' => (string) $validated['model_version'],
                    'source_device' => $validated['device_name'] ?? null,
                    'source_serial' => $validated['device_serial'] ?? null,
                    'captured_at' => $validated['captured_at'] ?? null,
                    'synced_at' => now(),
                    'is_active' => true,
                ];

                $template = $existingTemplate ?: new EmployeeFaceTemplate();
                $template->fill($attributes);
                $template->save();

                $faceMeta = is_array($employee->face_meta) ? $employee->face_meta : [];
                $faceMeta = array_merge($faceMeta, array_filter([
                    'last_face_template_hash' => $attributes['template_hash'],
                    'last_face_sync_source' => 'winadmin-faceid',
                    'last_face_runtime_version' => $validated['runtime_version'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''));

                $employee->markFaceEnrolled([
                    'face_status' => 'enrolled',
                    'face_samples_count' => 3,
                    'face_template_version' => $attributes['model_version'],
                    'face_updated_at' => $attributes['captured_at'] ?? now(),
                    'face_quality_score' => isset($attributes['quality_score']) ? (int) round($attributes['quality_score'] * 100) : null,
                    'device_serial' => $attributes['source_serial'],
                    'face_meta' => $faceMeta,
                ]);

                return $template;
            });
        } catch (\Throwable $exception) {
            Log::error('faceid.sync.failed', [
                'employee_id' => $employeeId,
                'fortia_employee_id' => $validated['fortia_employee_id'] ?? null,
                'template_hash' => substr($templateHash, 0, 12),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Face ID synchronization failed.',
            ], 500);
        }

        if ($existingTemplate) {
            Log::info('faceid.sync.duplicate', [
                'employee_id' => $employeeId,
                'template_id' => (int) $template->getKey(),
                'template_hash' => substr($templateHash, 0, 12),
            ]);
        } else {
            Log::info('faceid.sync.saved', [
                'employee_id' => $employeeId,
                'template_id' => (int) $template->getKey(),
                'template_hash' => substr($templateHash, 0, 12),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Face ID synchronized successfully',
            'template_id' => (int) $template->getKey(),
        ]);
    }

    private function resolveEmployee(array $validated): ?Employee
    {
        if (isset($validated['employee_id'])) {
            $employee = Employee::query()->find((int) $validated['employee_id']);
            if ($employee) {
                return $employee;
            }
        }

        $fortiaEmployeeId = trim((string) ($validated['fortia_employee_id'] ?? ''));
        if ($fortiaEmployeeId !== '') {
            $query = Employee::query()->where('fortia_employee_id', $fortiaEmployeeId);
            if (is_numeric($fortiaEmployeeId)) {
                $query->orWhere('fortia_employee_id', (int) $fortiaEmployeeId);
            }

            $employee = $query->first();
            if ($employee) {
                return $employee;
            }
        }

        $employeeCode = trim((string) ($validated['employee_code'] ?? ''));
        if ($employeeCode !== '' && is_numeric($employeeCode)) {
            return Employee::query()
                ->where('fortia_employee_id', (int) $employeeCode)
                ->first();
        }

        return null;
    }
}
