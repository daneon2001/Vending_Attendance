<?php

namespace App\Services\Vending;

use App\Enums\Vending\AttendanceBiometricResult;
use App\Enums\Vending\AttendanceReceiptErrorCode;
use App\Enums\Vending\AttendanceReceiptStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\VendingAttendanceEvent;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class VendingAttendanceReceiverService
{
    public function __construct(
        private readonly VendingAttendancePayloadValidator $validator,
        private readonly AttendancePayloadHashService $hashes,
        private readonly AttendanceManifestEvidenceService $manifestEvidence,
        private readonly AttendanceAuthorizationEvaluationService $authorization,
        private readonly AttendanceGeofenceEvidenceService $geofences,
        private readonly VendingAttendanceMetricsService $metrics,
    ) {}

    public function receive(Device $device, array $payload): array
    {
        $validation = $this->validator->validate($payload);
        $eventUuid = is_string($payload['event_uuid'] ?? null) ? strtolower($payload['event_uuid']) : null;

        if (! $validation['valid']) {
            return $this->reject(
                $device,
                $eventUuid,
                AttendanceReceiptErrorCode::INVALID_EVENT,
                ['error_fields' => $validation['error_fields'] ?? []],
            );
        }

        $data = $validation['data'];
        $eventUuid = $data['event_uuid'];
        $payloadHash = $this->hashes->hash([
            'device_uuid' => $device->uuid,
            'event' => $this->validator->hashablePayload($data),
        ]);
        $existing = VendingAttendanceEvent::query()->where('event_uuid', $eventUuid)->first();

        if ($existing) {
            return $this->existingResult($device, $existing, $payloadHash);
        }

        $receivedAt = CarbonImmutable::now('UTC');
        $timeError = $this->validateTime($data['captured_at'], $receivedAt);
        if ($timeError !== null) {
            return $this->reject($device, $eventUuid, $timeError);
        }

        if ($device->vending_machine_id === null) {
            return $this->reject($device, $eventUuid, AttendanceReceiptErrorCode::INVALID_EVENT);
        }

        try {
            $outcome = DB::transaction(function () use ($device, $data, $payloadHash, $receivedAt): array {
                $existing = VendingAttendanceEvent::query()
                    ->where('event_uuid', $data['event_uuid'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return ['created' => false, 'event' => $existing];
                }

                $machine = VendingMachine::query()
                    ->whereKey($device->vending_machine_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $employee = Employee::query()->whereKey($data['employee_id'])->first();

                if (! $employee) {
                    return ['created' => false, 'error' => AttendanceReceiptErrorCode::INVALID_EMPLOYEE];
                }

                $employeeEvidence = $this->manifestEvidence->classify(
                    $data['employee_manifest_version'],
                    (int) $machine->employee_manifest_version,
                );
                $configurationEvidence = $this->manifestEvidence->classify(
                    $data['configuration_version'],
                    (int) $machine->config_version,
                );
                $authorization = $this->authorization->evaluate(
                    $employee,
                    $machine,
                    $data['assignment_uuid'],
                    $data['captured_at'],
                    $employeeEvidence,
                    $configurationEvidence,
                );
                $geofence = $this->geofences->evaluate($machine, $data, $data['captured_at']);
                $assignment = $authorization['assignment'];
                $geofenceModel = $geofence['geofence'];
                $syncDelay = $receivedAt->getTimestamp() - $data['captured_at']->getTimestamp();

                $event = VendingAttendanceEvent::query()->create([
                    'event_uuid' => $data['event_uuid'],
                    'device_id' => $device->getKey(),
                    'vending_machine_id' => $machine->getKey(),
                    'employee_id' => $employee->getKey(),
                    'employee_machine_assignment_id' => $assignment?->getKey(),
                    'assignment_uuid_snapshot' => $data['assignment_uuid'],
                    'event_type' => $data['event_type'],
                    'captured_at_device' => $data['captured_at'],
                    'received_at_server' => $receivedAt,
                    'device_timezone' => $data['device_timezone'] ?? $machine->timezone,
                    'device_clock_drift_seconds' => $device->clock_drift_seconds,
                    'sync_delay_seconds' => $syncDelay,
                    'latitude' => $data['location']['latitude'] ?? null,
                    'longitude' => $data['location']['longitude'] ?? null,
                    'accuracy_m' => $data['location']['accuracy_m'] ?? null,
                    'location_evidence_status' => $geofence['location_status'],
                    'geofence_id' => $geofenceModel?->getKey(),
                    'geofence_version' => $data['geofence']['version'] ?? null,
                    'edge_geofence_result' => $geofence['edge_result'],
                    'server_geofence_result' => $geofence['server_result'],
                    'distance_m' => $geofence['distance_m'],
                    'effective_distance_m' => $geofence['effective_distance_m'],
                    'geofence_discrepancy' => $geofence['discrepancy'],
                    'authorization_result' => $authorization['result'],
                    'authorization_reason' => $authorization['reason'],
                    'employee_manifest_version' => $data['employee_manifest_version'],
                    'employee_manifest_evidence_status' => $employeeEvidence,
                    'configuration_version' => $data['configuration_version'],
                    'configuration_evidence_status' => $configurationEvidence,
                    'employee_number_snapshot' => $employee->visibleEmployeeKey(),
                    'assignment_type_snapshot' => $assignment?->assignment_type,
                    'assignment_valid_from_snapshot' => $assignment?->valid_from,
                    'assignment_valid_until_snapshot' => $assignment?->valid_until,
                    'attendance_allowed_snapshot' => $assignment?->attendance_allowed,
                    'machine_code_snapshot' => $machine->machine_code,
                    'geofence_radius_snapshot' => $geofenceModel?->radius_m,
                    'geofence_latitude_snapshot' => $geofenceModel?->center_latitude,
                    'geofence_longitude_snapshot' => $geofenceModel?->center_longitude,
                    'geofence_tolerance_snapshot' => $geofenceModel?->tolerance_m,
                    'biometric_result' => AttendanceBiometricResult::NOT_USED,
                    'biometric_reference' => null,
                    'sync_status' => AttendanceReceiptStatus::STORED,
                    'payload_hash' => $payloadHash,
                    'metadata' => $geofence['warnings'] === [] ? null : ['warnings' => $geofence['warnings']],
                    'created_at' => $receivedAt,
                ]);

                return [
                    'created' => true,
                    'event' => $event,
                    'warnings' => $geofence['warnings'],
                ];
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = VendingAttendanceEvent::query()->where('event_uuid', $eventUuid)->first();
            if ($existing) {
                return $this->existingResult($device, $existing, $payloadHash);
            }

            throw $exception;
        }

        if (isset($outcome['error'])) {
            return $this->reject($device, $eventUuid, $outcome['error']);
        }

        /** @var VendingAttendanceEvent $event */
        $event = $outcome['event'];
        if (! $outcome['created']) {
            return $this->existingResult($device, $event, $payloadHash);
        }

        $this->metrics->record($device, AttendanceReceiptStatus::STORED, $event);
        if ($event->geofence_discrepancy) {
            AuditLogger::log('attendance.geofence_mismatch', $event, 'Edge and server geofence results differ.', [
                'device_id' => $device->getKey(),
                'event_uuid' => $event->event_uuid,
                'geofence_version' => $event->geofence_version,
                'edge_result' => $event->edge_geofence_result?->value,
                'server_result' => $event->server_geofence_result->value,
            ]);
        }

        return [
            'event_uuid' => $event->event_uuid,
            'status' => AttendanceReceiptStatus::STORED->value,
            'remote_id' => (string) $event->getKey(),
            'authorization_result' => $event->authorization_result->value,
            'authorization_reason' => $event->authorization_reason?->value,
            'server_geofence_result' => $event->server_geofence_result->value,
            'warnings' => $outcome['warnings'],
            'received_at' => $event->received_at_server->utc()->toIso8601String(),
            'http_status' => 201,
        ];
    }

    private function existingResult(Device $device, VendingAttendanceEvent $existing, string $payloadHash): array
    {
        if ((int) $existing->device_id !== (int) $device->getKey()
            || ! hash_equals($existing->payload_hash, $payloadHash)) {
            AuditLogger::log('attendance.uuid_conflict', $existing, 'Attendance event UUID was reused with different evidence.', [
                'device_id' => $device->getKey(),
                'event_uuid' => $existing->event_uuid,
                'original_device_id' => $existing->device_id,
            ]);

            return $this->reject(
                $device,
                $existing->event_uuid,
                AttendanceReceiptErrorCode::EVENT_UUID_CONFLICT,
                ['remote_id' => (string) $existing->getKey()],
                true,
                409,
            );
        }

        $this->metrics->record($device, AttendanceReceiptStatus::DUPLICATE);

        return [
            'event_uuid' => $existing->event_uuid,
            'status' => AttendanceReceiptStatus::DUPLICATE->value,
            'remote_id' => (string) $existing->getKey(),
            'http_status' => 200,
        ];
    }

    private function validateTime(CarbonImmutable $capturedAt, CarbonImmutable $receivedAt): ?AttendanceReceiptErrorCode
    {
        $futureTolerance = max(0, (int) config('vending.attendance.future_tolerance_seconds', 300));
        $minimumYear = max(1970, (int) config('vending.attendance.minimum_captured_year', 2000));

        if ($capturedAt->year < $minimumYear || $capturedAt->isAfter($receivedAt->addSeconds($futureTolerance))) {
            return AttendanceReceiptErrorCode::INVALID_TIMESTAMP;
        }

        return null;
    }

    private function reject(
        Device $device,
        ?string $eventUuid,
        AttendanceReceiptErrorCode $code,
        array $extra = [],
        bool $recordMetric = true,
        int $httpStatus = 422,
    ): array {
        if ($recordMetric) {
            $this->metrics->record($device, AttendanceReceiptStatus::REJECTED);
        }

        return array_merge([
            'event_uuid' => $eventUuid,
            'status' => AttendanceReceiptStatus::REJECTED->value,
            'error_code' => $code->value,
            'http_status' => $httpStatus,
        ], $extra);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $sqlState === '23505' || in_array($driverCode, [19, 1062], true);
    }
}
