<?php

namespace App\Services\Vending;

use App\Enums\Vending\AttendanceGeofenceResult;
use App\Enums\Vending\AttendanceLocationEvidenceStatus;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use Carbon\CarbonImmutable;

class AttendanceGeofenceEvidenceService
{
    public function __construct(private readonly GeofenceValidationService $validation) {}

    public function evaluate(VendingMachine $machine, array $payload, CarbonImmutable $capturedAt): array
    {
        $location = $payload['location'];
        $geofenceInput = $payload['geofence'];
        $geofence = $geofenceInput === null
            ? null
            : MachineGeofence::query()
                ->where('vending_machine_id', $machine->getKey())
                ->where('version', $geofenceInput['version'])
                ->first();
        $edgeResult = $geofenceInput['edge_result'] ?? null;
        $warnings = [];

        if ($location === null) {
            return $this->result(
                AttendanceLocationEvidenceStatus::MISSING,
                $geofence,
                $edgeResult,
                AttendanceGeofenceResult::NOT_EVALUATED,
                null,
                null,
                false,
                $geofenceInput === null ? [] : ($geofence ? [] : ['UNKNOWN_GEOFENCE_VERSION']),
            );
        }

        $latitude = $location['latitude'];
        $longitude = $location['longitude'];
        $accuracy = $location['accuracy_m'];
        $validCoordinates = $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180
            && ! ($latitude === 0.0 && $longitude === 0.0)
            && $accuracy >= 0;

        if (! $validCoordinates) {
            return $this->result(
                AttendanceLocationEvidenceStatus::INVALID,
                $geofence,
                $edgeResult,
                AttendanceGeofenceResult::NOT_EVALUATED,
                null,
                null,
                false,
                array_values(array_unique(array_merge(
                    ['INVALID_LOCATION'],
                    $geofenceInput !== null && ! $geofence ? ['UNKNOWN_GEOFENCE_VERSION'] : [],
                ))),
            );
        }

        if ($geofenceInput === null) {
            return $this->result(
                AttendanceLocationEvidenceStatus::VALID,
                null,
                null,
                AttendanceGeofenceResult::NOT_EVALUATED,
                null,
                null,
                false,
                ['GEOFENCE_VERSION_MISSING'],
            );
        }

        if (! $geofence) {
            return $this->result(
                AttendanceLocationEvidenceStatus::VALID,
                null,
                $edgeResult,
                AttendanceGeofenceResult::NOT_EVALUATED,
                null,
                null,
                false,
                ['UNKNOWN_GEOFENCE_VERSION'],
            );
        }

        $locationStatus = $geofence->minimum_acceptable_accuracy_m !== null
            && $accuracy > (float) $geofence->minimum_acceptable_accuracy_m
                ? AttendanceLocationEvidenceStatus::LOW_ACCURACY
                : AttendanceLocationEvidenceStatus::VALID;
        $server = $this->validation->validate($geofence, $latitude, $longitude, $accuracy, $capturedAt);
        $serverResult = AttendanceGeofenceResult::from($server['result']);
        $discrepancy = $edgeResult !== null && $edgeResult !== $serverResult->value;

        if ($discrepancy) {
            $warnings[] = 'EDGE_SERVER_GEOFENCE_MISMATCH';
        }

        return $this->result(
            $locationStatus,
            $geofence,
            $edgeResult,
            $serverResult,
            (float) $server['distance_m'],
            (float) $server['effective_distance_m'],
            $discrepancy,
            $warnings,
        );
    }

    private function result(
        AttendanceLocationEvidenceStatus $locationStatus,
        ?MachineGeofence $geofence,
        ?string $edgeResult,
        AttendanceGeofenceResult $serverResult,
        ?float $distance,
        ?float $effectiveDistance,
        bool $discrepancy,
        array $warnings,
    ): array {
        return [
            'location_status' => $locationStatus,
            'geofence' => $geofence,
            'edge_result' => $edgeResult !== null ? AttendanceGeofenceResult::from($edgeResult) : null,
            'server_result' => $serverResult,
            'distance_m' => $distance,
            'effective_distance_m' => $effectiveDistance,
            'discrepancy' => $discrepancy,
            'warnings' => $warnings,
        ];
    }
}
