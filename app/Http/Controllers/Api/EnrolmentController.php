<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrolmentCompleteRequest;
use App\Models\Clock;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Models\EnrolmentAudit;
use App\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EnrolmentController extends Controller
{
    public function complete(EnrolmentCompleteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $enrolmentType = (string) $validated['enrolment_type'];
        $vendorTemplateId = (string) $validated['template_vendor_id'];
        $employee = $this->resolveEmployee($validated);
        $requestedFortiaEmployeeId = trim((string) ($validated['fortia_employee_id'] ?? ''));

        if (! $employee) {
            return response()->json([
                'success' => false,
                'employee_id' => null,
                'fortia_employee_id' => $requestedFortiaEmployeeId !== '' ? $requestedFortiaEmployeeId : null,
                'clock_id' => isset($validated['clock_id']) ? (int) $validated['clock_id'] : null,
                'unit_id' => isset($validated['unit_id']) ? (int) $validated['unit_id'] : null,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'REJECTED',
                'message' => 'Employee not found.',
            ], 404);
        }

        $employeeId = (int) $employee->getKey();
        $fortiaEmployeeId = $employee->fortia_employee_id !== null
            ? (string) $employee->fortia_employee_id
            : (string) $employeeId;

        if ($requestedFortiaEmployeeId !== '' && $fortiaEmployeeId !== '' && $requestedFortiaEmployeeId !== $fortiaEmployeeId) {
            return response()->json([
                'success' => false,
                'employee_id' => $employeeId,
                'fortia_employee_id' => $fortiaEmployeeId,
                'clock_id' => isset($validated['clock_id']) ? (int) $validated['clock_id'] : null,
                'unit_id' => isset($validated['unit_id']) ? (int) $validated['unit_id'] : null,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'REJECTED',
                'message' => 'The provided fortia_employee_id does not match the resolved employee_id.',
            ], 422);
        }

        $clock = $this->resolveClock($validated);
        $resolvedUnitId = $this->resolveUnitId($validated, $clock, $employee);

        if (isset($validated['unit_id']) && ! $this->isAcceptedUnitId((int) $validated['unit_id'], $employee)) {
            $auditPayload = $this->mergeAuditPayload($validated, $employeeId, $clock?->id ? (int) $clock->id : null, null);
            $this->audit($auditPayload, EnrolmentAudit::STATUS_REJECTED, 'The selected unit id is invalid.');

            return response()->json([
                'message' => 'The selected unit id is invalid.',
                'errors' => [
                    'unit_id' => ['The selected unit id is invalid.'],
                ],
            ], 422);
        }

        if ($clock && isset($validated['unit_id']) && $clock->location_id !== null && (int) $validated['unit_id'] !== (int) $clock->location_id) {
            $auditPayload = $this->mergeAuditPayload($validated, $employeeId, (int) $clock->id, $resolvedUnitId);
            $this->audit($auditPayload, EnrolmentAudit::STATUS_REJECTED, 'Clock does not belong to the provided unit.');

            return response()->json([
                'success' => false,
                'employee_id' => $employeeId,
                'fortia_employee_id' => $fortiaEmployeeId,
                'clock_id' => (int) $clock->id,
                'unit_id' => $resolvedUnitId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'REJECTED',
                'message' => 'Clock does not belong to the provided unit.',
            ], 422);
        }

        if ($resolvedUnitId === null) {
            $auditPayload = $this->mergeAuditPayload($validated, $employeeId, $clock?->id ? (int) $clock->id : null, null);
            $this->audit($auditPayload, EnrolmentAudit::STATUS_REJECTED, 'Unable to resolve unit for enrolment.');

            return response()->json([
                'success' => false,
                'employee_id' => $employeeId,
                'fortia_employee_id' => $fortiaEmployeeId,
                'clock_id' => $clock?->id ? (int) $clock->id : null,
                'unit_id' => null,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'REJECTED',
                'message' => 'Unable to resolve unit for enrolment.',
            ], 422);
        }

        $clockId = $clock?->id ? (int) $clock->id : null;
        $auditPayload = $this->mergeAuditPayload($validated, $employeeId, $clockId, $resolvedUnitId);

        if (! $this->isActiveEmployee($employee->status)) {
            $this->audit($auditPayload, EnrolmentAudit::STATUS_REJECTED, 'Employee is not active.');

            return response()->json([
                'success' => false,
                'employee_id' => $employeeId,
                'fortia_employee_id' => $fortiaEmployeeId,
                'clock_id' => $clockId,
                'unit_id' => $resolvedUnitId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'REJECTED',
                'message' => 'Employee is not active.',
            ], 422);
        }

        $existingByVendor = EmployeeFingerprint::query()
            ->where('vendor_template_id', $vendorTemplateId)
            ->first();
        $existingTemplateIsIncomplete = $existingByVendor
            && (int) $existingByVendor->employee_id === $employeeId
            && $enrolmentType === EmployeeFingerprint::TYPE_FINGERPRINT
            && (
                blank($existingByVendor->template_b64)
                || blank($existingByVendor->template_format)
            );

        if ($existingByVendor && (int) $existingByVendor->employee_id !== $employeeId) {
            $this->audit($auditPayload, EnrolmentAudit::STATUS_CONFLICT, 'Template is already assigned to another employee.');

            return response()->json([
                'success' => true,
                'employee_id' => $employeeId,
                'fortia_employee_id' => $fortiaEmployeeId,
                'clock_id' => $clockId,
                'unit_id' => $resolvedUnitId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'CONFLICT',
                'message' => 'Template already linked to another employee.',
            ], 409);
        }

        if ($existingByVendor && (int) $existingByVendor->employee_id === $employeeId) {
            if (
                ! $request->filled('template_b64')
                && ! $request->filled('template_format')
                && ! $request->filled('device_serial')
                && ! $existingTemplateIsIncomplete
            ) {
                $employee->refreshFingerprintFlag();
                $employee->refresh();
                $this->audit($auditPayload, EnrolmentAudit::STATUS_DUPLICATE, 'Already enrolled.');

                return response()->json([
                    'success' => true,
                    'employee_id' => $employeeId,
                    'fortia_employee_id' => $fortiaEmployeeId,
                    'clock_id' => $clockId,
                    'unit_id' => $resolvedUnitId,
                    'vendor_template_id' => $vendorTemplateId,
                    'action' => 'ALREADY',
                    'message' => 'Already enrolled',
                    'has_fingerprint' => (bool) $employee->has_fingerprint,
                    'fingerprint_status' => (string) $employee->fingerprint_status,
                ]);
            }
        }

        if ($enrolmentType === EmployeeFingerprint::TYPE_FINGERPRINT) {
            if ($existingTemplateIsIncomplete && (! $request->filled('template_b64') || ! $request->filled('template_format'))) {
                $this->audit($auditPayload, EnrolmentAudit::STATUS_REJECTED, 'Existing fingerprint template is incomplete and requires template_b64/template_format.');

                return response()->json([
                    'message' => EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_REQUIRED_MESSAGE,
                    'errors' => [
                        'template_b64' => [EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_REQUIRED_MESSAGE],
                        'template_format' => [EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_FORMAT_REQUIRED_MESSAGE],
                    ],
                ], 422);
            }

            $invalidFingerprintResponse = $this->validateNewFingerprintTemplate($request, $auditPayload);
            if ($invalidFingerprintResponse instanceof JsonResponse) {
                return $invalidFingerprintResponse;
            }
        } elseif (! $request->filled('template_b64')) {
            $this->audit($auditPayload, EnrolmentAudit::STATUS_REJECTED, 'template_b64 is required for new enrolments.');

            return response()->json([
                'message' => EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_REQUIRED_MESSAGE,
                'errors' => [
                    'template_b64' => [EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_REQUIRED_MESSAGE],
                ],
            ], 422);
        }

        $templatePayload = [
            'clock_id' => $clockId ?? $existingByVendor?->clock_id,
            'status' => 'enrolled',
            'enrolment_type' => $validated['enrolment_type'],
            'template_b64' => $validated['template_b64'] ?? $existingByVendor?->template_b64,
            'template_format' => $validated['template_format'] ?? $existingByVendor?->template_format,
            'template_vendor' => $this->resolveTemplateVendor($enrolmentType),
            'template_source' => $this->resolveTemplateSource($enrolmentType),
            'device_serial' => $validated['device_serial'] ?? $existingByVendor?->device_serial,
            'enrolled_at' => $validated['performed_at'],
            'performed_at' => $validated['performed_at'],
            'deleted_at' => null,
        ];

        $action = $existingByVendor && (int) $existingByVendor->employee_id === $employeeId
            ? 'UPDATED'
            : 'CREATED';
        $message = $action === 'UPDATED'
            ? 'Enrolment updated.'
            : 'Enrolment stored.';

        try {
            DB::transaction(function () use ($existingByVendor, $templatePayload, $employeeId, $vendorTemplateId, $employee, $enrolmentType, $validated, &$action, &$message): void {
                if ($existingByVendor && (int) $existingByVendor->employee_id === $employeeId) {
                    $existingByVendor->fill($templatePayload)->save();
                } else {
                    EmployeeFingerprint::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'vendor_template_id' => $vendorTemplateId,
                        ],
                        $templatePayload
                    );
                }

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
            if (! $this->isUniqueConstraintException($e)) {
                throw $e;
            }

            $this->audit($auditPayload, EnrolmentAudit::STATUS_CONFLICT, 'Unique constraint conflict while creating enrolment.');

            return response()->json([
                'success' => true,
                'employee_id' => $employeeId,
                'fortia_employee_id' => $fortiaEmployeeId,
                'clock_id' => $clockId,
                'unit_id' => $resolvedUnitId,
                'vendor_template_id' => $vendorTemplateId,
                'action' => 'CONFLICT',
                'message' => 'Template conflict detected.',
            ], 409);
        }

        $employee->refresh();
        $this->audit(
            $auditPayload,
            EnrolmentAudit::STATUS_SENT,
            $message
        );

        return response()->json([
            'success' => true,
            'employee_id' => $employeeId,
            'fortia_employee_id' => $fortiaEmployeeId,
            'clock_id' => $clockId,
            'unit_id' => $resolvedUnitId,
            'vendor_template_id' => $vendorTemplateId,
            'action' => $action,
            'message' => $message,
            'has_fingerprint' => (bool) $employee->has_fingerprint,
            'fingerprint_status' => (string) $employee->fingerprint_status,
        ]);
    }

    private function isActiveEmployee(?string $status): bool
    {
        return in_array(strtoupper((string) $status), ['A', 'ACTIVE'], true);
    }

    private function validateNewFingerprintTemplate(EnrolmentCompleteRequest $request, array $validated): ?JsonResponse
    {
        if (! $request->filled('template_b64')) {
            $this->audit($validated, EnrolmentAudit::STATUS_REJECTED, 'template_b64 is required for new fingerprint enrolments.');

            return response()->json([
                'message' => EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_REQUIRED_MESSAGE,
                'errors' => [
                    'template_b64' => [EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_REQUIRED_MESSAGE],
                ],
            ], 422);
        }

        if (! $request->filled('template_format')) {
            $this->audit($validated, EnrolmentAudit::STATUS_REJECTED, 'template_format is required for fingerprint enrolments.');

            return response()->json([
                'message' => EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_FORMAT_REQUIRED_MESSAGE,
                'errors' => [
                    'template_format' => [EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_FORMAT_REQUIRED_MESSAGE],
                ],
            ], 422);
        }

        $templateB64 = (string) ($validated['template_b64'] ?? '');
        if (! $request->fingerprintTemplateB64IsValid($templateB64)) {
            $this->audit($validated, EnrolmentAudit::STATUS_REJECTED, 'template_b64 is not valid Base64 for fingerprint enrolment.');

            return response()->json([
                'message' => EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_INVALID_B64_MESSAGE,
                'errors' => [
                    'template_b64' => [EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_INVALID_B64_MESSAGE],
                ],
            ], 422);
        }

        $templateFormat = (string) ($validated['template_format'] ?? '');
        if (! $request->fingerprintTemplateFormatIsAllowed($templateFormat)) {
            $this->audit($validated, EnrolmentAudit::STATUS_REJECTED, 'template_format is not allowed for fingerprint enrolment.');

            return response()->json([
                'message' => EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_FORMAT_INVALID_MESSAGE,
                'errors' => [
                    'template_format' => [EnrolmentCompleteRequest::FINGERPRINT_TEMPLATE_FORMAT_INVALID_MESSAGE],
                ],
            ], 422);
        }

        return null;
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

    private function resolveEmployee(array $validated): ?Employee
    {
        if (isset($validated['employee_id'])) {
            return Employee::query()->find((int) $validated['employee_id']);
        }

        $fortiaEmployeeId = trim((string) ($validated['fortia_employee_id'] ?? ''));
        if ($fortiaEmployeeId === '') {
            return null;
        }

        if (is_numeric($fortiaEmployeeId)) {
            return Employee::query()
                ->where('fortia_employee_id', (int) $fortiaEmployeeId)
                ->first();
        }

        return null;
    }

    private function isUniqueConstraintException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '19'], true);
    }

    private function resolveClock(array $validated): ?Clock
    {
        if (isset($validated['clock_id'])) {
            return Clock::query()->find((int) $validated['clock_id']);
        }

        if (isset($validated['unit_id'])) {
            return Clock::query()
                ->where('location_id', (int) $validated['unit_id'])
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    private function isAcceptedUnitId(int $unitId, Employee $employee): bool
    {
        if ($unitId <= 0) {
            return false;
        }

        if (Location::query()->whereKey($unitId)->exists()) {
            return true;
        }

        return $employee->base_location_id !== null
            && (int) $employee->base_location_id === $unitId;
    }

    private function resolveUnitId(array $validated, ?Clock $clock, Employee $employee): ?int
    {
        if (isset($validated['unit_id'])) {
            return (int) $validated['unit_id'];
        }

        if ($clock && $clock->location_id !== null) {
            return (int) $clock->location_id;
        }

        if ($employee->base_location_id !== null) {
            return (int) $employee->base_location_id;
        }

        return null;
    }

    private function mergeAuditPayload(array $validated, int $employeeId, ?int $clockId, ?int $unitId): array
    {
        return array_merge($validated, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'unit_id' => $unitId,
        ]);
    }

    private function resolveTemplateVendor(string $enrolmentType): string
    {
        if ($enrolmentType === EmployeeFingerprint::TYPE_FACE) {
            return (string) config('biometrics.face.default_vendor', 'digitalpersona');
        }

        return (string) config('biometrics.fingerprint.default_vendor', 'digitalpersona');
    }

    private function resolveTemplateSource(string $enrolmentType): string
    {
        if ($enrolmentType === EmployeeFingerprint::TYPE_FACE) {
            return (string) config('biometrics.face.default_source', 'camera');
        }

        return (string) config('biometrics.fingerprint.default_source', 'scanner');
    }
}
