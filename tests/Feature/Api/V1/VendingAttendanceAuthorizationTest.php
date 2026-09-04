<?php

namespace Tests\Feature\Api\V1;

use App\Services\Vending\MachineAssignmentService;
use Illuminate\Support\Str;

class VendingAttendanceAuthorizationTest extends VendingDeviceApiTestCase
{
    public function test_assignment_is_evaluated_at_event_time_with_typed_denial_reasons(): void
    {
        $machine = $this->machine('ATT-AUTH');
        $geofence = $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'ATT-AUTH-DEVICE');

        $validEmployee = $this->employee();
        $valid = $this->assignment($machine, $validEmployee);
        $this->receive($provisioned, $this->attendancePayload($machine, $validEmployee, $valid, $geofence))
            ->assertJsonPath('authorization_result', 'AUTHORIZED')
            ->assertJsonPath('authorization_reason', 'ASSIGNMENT_VALID');

        $expiredEmployee = $this->employee();
        $expired = $this->assignment($machine, $expiredEmployee, [
            'valid_from' => now()->subDays(3),
            'valid_until' => now()->subDay(),
        ]);
        $this->receive($provisioned, $this->attendancePayload($machine, $expiredEmployee, $expired, $geofence))
            ->assertJsonPath('authorization_result', 'DENIED')
            ->assertJsonPath('authorization_reason', 'ASSIGNMENT_EXPIRED');

        $futureEmployee = $this->employee();
        $future = $this->assignment($machine, $futureEmployee, ['valid_from' => now()->addDay()]);
        $this->receive($provisioned, $this->attendancePayload($machine, $futureEmployee, $future, $geofence))
            ->assertJsonPath('authorization_result', 'DENIED')
            ->assertJsonPath('authorization_reason', 'ASSIGNMENT_NOT_YET_EFFECTIVE');

        $revokedEmployee = $this->employee();
        $revoked = $this->assignment($machine, $revokedEmployee, ['valid_from' => now()->subDays(2)]);
        $revoked = app(MachineAssignmentService::class)->revoke($revoked, null, 'Test revocation');
        $this->receive($provisioned, $this->attendancePayload($machine, $revokedEmployee, $revoked, $geofence, [
            'captured_at' => now()->addSecond()->utc()->toIso8601String(),
        ]))->assertJsonPath('authorization_result', 'DENIED')
            ->assertJsonPath('authorization_reason', 'ASSIGNMENT_REVOKED');

        $deniedEmployee = $this->employee();
        $denied = $this->assignment($machine, $deniedEmployee, ['attendance_allowed' => false]);
        $this->receive($provisioned, $this->attendancePayload($machine, $deniedEmployee, $denied, $geofence))
            ->assertJsonPath('authorization_result', 'DENIED')
            ->assertJsonPath('authorization_reason', 'ATTENDANCE_NOT_ALLOWED');
    }

    public function test_wrong_machine_unknown_assignment_and_inactive_employee_are_not_silently_authorized(): void
    {
        $machine = $this->machine('ATT-AUTH-SCOPE');
        $otherMachine = $this->machine('ATT-AUTH-OTHER');
        $geofence = $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'ATT-AUTH-SCOPE-DEVICE');
        $employee = $this->employee();
        $otherAssignment = $this->assignment($otherMachine, $employee);

        $this->receive($provisioned, $this->attendancePayload($machine, $employee, $otherAssignment, $geofence))
            ->assertJsonPath('authorization_result', 'DENIED')
            ->assertJsonPath('authorization_reason', 'ASSIGNMENT_MACHINE_MISMATCH');

        $localAssignment = $this->assignment($machine, $employee);
        $this->receive($provisioned, $this->attendancePayload($machine, $employee, $localAssignment, $geofence, [
            'assignment_uuid' => (string) Str::uuid(),
        ]))->assertJsonPath('authorization_result', 'UNVERIFIABLE')
            ->assertJsonPath('authorization_reason', 'ASSIGNMENT_NOT_FOUND');

        $employee->update(['status' => 'B']);
        $this->receive($provisioned, $this->attendancePayload($machine, $employee->fresh(), $localAssignment, $geofence))
            ->assertJsonPath('authorization_result', 'DENIED')
            ->assertJsonPath('authorization_reason', 'EMPLOYEE_INACTIVE');
    }

    public function test_historical_inactive_employee_is_unverifiable_when_prior_status_cannot_be_reconstructed(): void
    {
        $machine = $this->machine('ATT-AUTH-HISTORICAL');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee, ['valid_from' => now()->subDays(5)]);
        $geofence = $this->geofence($machine);
        $manifestAtCapture = (int) $machine->fresh()->employee_manifest_version;
        $employee->update(['status' => 'B']);
        $provisioned = $this->provisionedDevice($machine, 'ATT-AUTH-HISTORICAL-DEVICE');
        $payload = $this->attendancePayload($machine, $employee->fresh(), $assignment, $geofence, [
            'captured_at' => now()->subDays(2)->utc()->toIso8601String(),
            'employee_manifest_version' => $manifestAtCapture,
        ]);

        $this->receive($provisioned, $payload)
            ->assertCreated()
            ->assertJsonPath('authorization_result', 'UNVERIFIABLE')
            ->assertJsonPath('authorization_reason', 'HISTORICAL_STATE_UNAVAILABLE');

        $this->assertDatabaseHas('vending_attendance_events', [
            'event_uuid' => $payload['event_uuid'],
            'employee_manifest_evidence_status' => 'STALE',
            'employee_number_snapshot' => (string) $employee->visibleEmployeeKey(),
        ]);
    }

    public function test_stale_manifest_is_evidence_and_does_not_reject_otherwise_valid_offline_event(): void
    {
        $machine = $this->machine('ATT-AUTH-STALE');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee, ['valid_from' => now()->subDays(3)]);
        $geofence = $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'ATT-AUTH-STALE-DEVICE');
        $payload = $this->attendancePayload($machine, $employee, $assignment, $geofence, [
            'captured_at' => now()->subDay()->utc()->toIso8601String(),
            'employee_manifest_version' => 1,
            'configuration_version' => 1,
        ]);

        $this->receive($provisioned, $payload)
            ->assertCreated()
            ->assertJsonPath('authorization_result', 'AUTHORIZED');

        $this->assertDatabaseHas('vending_attendance_events', [
            'event_uuid' => $payload['event_uuid'],
            'employee_manifest_evidence_status' => 'STALE',
            'configuration_evidence_status' => 'STALE',
        ]);
    }

    private function receive(array $provisioned, array $payload)
    {
        return $this->signedDeviceRequest(
            'POST',
            '/api/v1/device/attendance/events',
            $payload,
            $provisioned['device']->fresh(),
            $provisioned['credential'],
        )->assertCreated();
    }
}
