<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEmployeeFaceProfileRequest;
use App\Http\Resources\EmployeeCompactResource;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Models\EmployeeTemplateDeletion;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeFaceProfileController extends Controller
{
    public function update(UpdateEmployeeFaceProfileRequest $request, Employee $employee): JsonResponse
    {
        $before = $this->snapshot($employee);
        $validated = $request->validated();
        $payload = [];

        foreach ([
            'face_enabled',
            'face_status',
            'face_samples_count',
            'face_template_version',
            'face_quality_score',
            'face_meta',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (($payload['face_status'] ?? null) === 'disabled') {
            $payload['face_enabled'] = false;
        }

        if (($payload['face_enabled'] ?? null) === false && ! array_key_exists('face_status', $payload) && $employee->has_face_enrollment) {
            $payload['face_status'] = 'disabled';
        }

        if (($payload['face_enabled'] ?? null) === true && ! array_key_exists('face_status', $payload) && $employee->has_face_enrollment) {
            $payload['face_status'] = 'enrolled';
        }

        $employee->forceFill($payload)->save();

        $this->touchFaceTemplates($employee);

        $employee->refresh();
        $after = $this->snapshot($employee);

        if ($before['face_sync_ready'] && ! $after['face_sync_ready']) {
            $this->recordFaceTombstones($employee);
        }

        AuditLogger::log(
            event: 'employees.face_profile_updated',
            auditable: $employee,
            description: 'Perfil de Face ID actualizado',
            metadata: [
                'action' => 'biometric.face.updated',
                'entity' => 'employees',
                'reason' => 'face_profile_updated',
                'before' => $before,
                'after' => $after,
            ],
        );

        return response()->json([
            'ok' => true,
            'employee' => (new EmployeeCompactResource($employee->loadMissing('unit:id,name')))->resolve(),
        ]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $before = $this->snapshot($employee);

        $templates = $employee->faceTemplates()
            ->select(['id', 'vendor_template_id'])
            ->get();

        $templateIds = $templates
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        DB::transaction(function () use ($employee, $templates, $templateIds): void {
            $deletedAt = now();

            foreach ($templates as $template) {
                if (! empty($template->vendor_template_id)) {
                    $payload = [
                        'vendor' => 'digitalpersona',
                        'vendor_template_id' => (string) $template->vendor_template_id,
                        'employee_id' => $employee->id,
                        'deleted_at' => $deletedAt,
                    ];

                    if (Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
                        $payload['biometric_type'] = EmployeeFingerprint::TYPE_FACE;
                    }

                    EmployeeTemplateDeletion::query()->create($payload);
                }
            }

            if ($templateIds !== []) {
                EmployeeFingerprint::query()->whereIn('id', $templateIds)->delete();
            }

            $employee->refreshFaceEnrollmentState();
        });

        $employee->refresh();
        $after = $this->snapshot($employee);

        AuditLogger::log(
            event: 'employees.face_profile_cleared',
            auditable: $employee,
            description: 'Face ID eliminado del empleado',
            metadata: [
                'action' => 'biometric.face.deleted',
                'entity' => 'employee_fingerprints',
                'reason' => 'face_enrollment_deleted',
                'before' => $before,
                'after' => $after,
                'new_values' => [
                    'employee_id' => (int) $employee->id,
                    'deleted_count' => count($templateIds),
                ],
            ],
        );

        return response()->json([
            'ok' => true,
            'deleted_count' => count($templateIds),
            'employee' => (new EmployeeCompactResource($employee->loadMissing('unit:id,name')))->resolve(),
        ]);
    }

    private function touchFaceTemplates(Employee $employee): void
    {
        $hasTemplates = $employee->faceTemplates()->exists();

        if (! $hasTemplates) {
            return;
        }

        EmployeeFingerprint::query()
            ->where('employee_id', $employee->id)
            ->face()
            ->update(['updated_at' => now()]);
    }

    private function recordFaceTombstones(Employee $employee): void
    {
        $templates = $employee->faceTemplates()
            ->whereNotNull('vendor_template_id')
            ->get(['vendor_template_id']);

        if ($templates->isEmpty()) {
            return;
        }

        $deletedAt = now();

        foreach ($templates as $template) {
            $payload = [
                'vendor' => 'digitalpersona',
                'vendor_template_id' => (string) $template->vendor_template_id,
                'employee_id' => $employee->id,
                'deleted_at' => $deletedAt,
            ];

            if (Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
                $payload['biometric_type'] = EmployeeFingerprint::TYPE_FACE;
            }

            EmployeeTemplateDeletion::query()->create($payload);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Employee $employee): array
    {
        return [
            'has_face_enrollment' => (bool) $employee->has_face_enrollment,
            'face_status' => (string) ($employee->face_status ?? 'none'),
            'face_enabled' => (bool) ($employee->face_enabled ?? false),
            'face_samples_count' => (int) ($employee->face_samples_count ?? 0),
            'face_template_version' => $employee->face_template_version,
            'face_quality_score' => is_numeric($employee->face_quality_score) ? (int) $employee->face_quality_score : null,
            'face_updated_at' => optional($employee->face_updated_at)->toISOString(),
            'face_sync_ready' => (bool) ($employee->face_sync_ready ?? false),
        ];
    }
}
