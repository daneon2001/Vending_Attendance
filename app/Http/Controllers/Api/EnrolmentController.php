<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrolmentCompleteRequest;
use App\Models\Clock;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Models\EnrolmentAudit;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EnrolmentController extends Controller
{
    public function complete(EnrolmentCompleteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $employeeId = (int) $validated['employee_id'];
        $clockId = (int) $validated['clock_id'];
        $enrolmentType = (string) $validated['enrolment_type'];
        $vendorTemplateId = (string) $validated['template_vendor_id'];

        $employee = Employee::query()->findOrFail($employeeId);
        if (! $this->isActiveEmployee($employee->status)) {
            $this->audit($validated, EnrolmentAudit::STATUS_REJECTED, 'Employee is not active.');

            return response()->json([
                'success' => false,
                'employee_id' => $employeeId,
                'clock_id' => $clockId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'REJECTED',
                'message' => 'Employee is not active.',
            ], 422);
        }

        $clock = Clock::query()->findOrFail($clockId);
        if (! $clock->location_id) {
            $this->audit($validated, EnrolmentAudit::STATUS_REJECTED, 'Clock is not assigned to any unit.');

            return response()->json([
                'success' => false,
                'employee_id' => $employeeId,
                'clock_id' => $clockId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'REJECTED',
                'message' => 'Clock is not assigned to any unit.',
            ], 422);
        }

        $existingByVendor = EmployeeFingerprint::query()
            ->where('vendor_template_id', $vendorTemplateId)
            ->first();

        if ($existingByVendor && (int) $existingByVendor->employee_id !== $employeeId) {
            $this->audit($validated, EnrolmentAudit::STATUS_CONFLICT, 'Template is already assigned to another employee.');

            return response()->json([
                'success' => true,
                'employee_id' => $employeeId,
                'clock_id' => $clockId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'CONFLICT',
                'message' => 'Template already linked to another employee.',
            ], 409);
        }

        if ($existingByVendor && (int) $existingByVendor->employee_id === $employeeId) {
            $this->audit($validated, EnrolmentAudit::STATUS_DUPLICATE, 'Already enrolled.');

            return response()->json([
                'success' => true,
                'employee_id' => $employeeId,
                'clock_id' => $clockId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'ALREADY',
                'message' => 'Already enrolled',
            ]);
        }

        if (! $request->filled('template_b64')) {
            $this->audit($validated, EnrolmentAudit::STATUS_REJECTED, 'template_b64 is required for new enrolments.');

            return response()->json([
                'message' => 'The template_b64 field is required for new enrolments.',
                'errors' => [
                    'template_b64' => ['The template_b64 field is required for new enrolments.'],
                ],
            ], 422);
        }

        try {
            DB::transaction(function () use ($validated, $employee, $enrolmentType, $vendorTemplateId): void {
                EmployeeFingerprint::updateOrCreate(
                    [
                        'employee_id' => (int) $validated['employee_id'],
                        'vendor_template_id' => (string) $validated['template_vendor_id'],
                    ],
                    [
                        'clock_id' => (int) $validated['clock_id'],
                        'status' => 'enrolled',
                        'enrolment_type' => $validated['enrolment_type'],
                        'template_b64' => $validated['template_b64'],
                        'template_format' => $validated['template_format'] ?? null,
                        'device_serial' => $validated['device_serial'] ?? null,
                        'enrolled_at' => $validated['performed_at'],
                        'performed_at' => $validated['performed_at'],
                        'deleted_at' => null,
                    ]
                );

                $employee->refreshFingerprintFlag();

                if ($enrolmentType === EmployeeFingerprint::TYPE_FACE) {
                    $faceMeta = $employee->face_meta;
                    if (! is_array($faceMeta)) {
                        $faceMeta = [];
                    }

                    if (! empty($validated['metadata']) && is_array($validated['metadata'])) {
                        $faceMeta = array_merge($faceMeta, $validated['metadata']);
                    }

                    $employee->markFaceEnrolled([
                        'face_samples_count' => (int) ($validated['samples_count'] ?? 1),
                        'face_template_version' => $validated['template_version'] ?? null,
                        'face_updated_at' => $validated['performed_at'],
                        'face_quality_score' => isset($validated['quality_score']) ? (int) $validated['quality_score'] : null,
                        'vendor_template_id' => $vendorTemplateId,
                        'template_format' => $validated['template_format'] ?? null,
                        'device_serial' => $validated['device_serial'] ?? null,
                        'face_meta' => $faceMeta,
                    ]);
                }
            });
        } catch (QueryException $e) {
            $this->audit($validated, EnrolmentAudit::STATUS_CONFLICT, 'Unique constraint conflict while creating enrolment.');

            return response()->json([
                'success' => true,
                'employee_id' => $employeeId,
                'clock_id' => $clockId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'CONFLICT',
                'message' => 'Template conflict detected.',
            ], 409);
        }

        $this->audit($validated, EnrolmentAudit::STATUS_SENT, 'Enrolment stored.');

        return response()->json([
            'success' => true,
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => $vendorTemplateId,
            'action' => 'CREATED',
        ]);
    }

    private function isActiveEmployee(?string $status): bool
    {
        return in_array(strtoupper((string) $status), ['A', 'ACTIVE'], true);
    }

    private function audit(array $payload, string $status, string $reason): void
    {
        EnrolmentAudit::query()->create([
            'employee_id' => isset($payload['employee_id']) ? (int) $payload['employee_id'] : null,
            'clock_id' => isset($payload['clock_id']) ? (int) $payload['clock_id'] : null,
            'enrolment_type' => $payload['enrolment_type'] ?? null,
            'vendor_template_id' => $payload['template_vendor_id'] ?? null,
            'device_serial' => $payload['device_serial'] ?? null,
            'performed_at' => $payload['performed_at'] ?? null,
            'status' => $status,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
