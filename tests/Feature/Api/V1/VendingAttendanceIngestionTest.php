<?php

namespace Tests\Feature\Api\V1;

use App\Models\VendingAttendanceEvent;

class VendingAttendanceIngestionTest extends VendingDeviceApiTestCase
{
    public function test_same_uuid_and_payload_is_stored_once_then_reported_as_duplicate(): void
    {
        $machine = $this->machine('ATT-IDEMPOTENT');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $geofence = $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'ATT-IDEMPOTENT-DEVICE');
        $provisioned['device']->forceFill(['clock_drift_seconds' => 42])->save();
        $payload = $this->attendancePayload($machine, $employee, $assignment, $geofence);

        $stored = $this->signedDeviceRequest(
            'POST',
            '/api/v1/device/attendance/events',
            $payload,
            $provisioned['device']->fresh(),
            $provisioned['credential'],
        )->assertCreated()
            ->assertJsonPath('status', 'STORED')
            ->assertJsonPath('authorization_result', 'AUTHORIZED')
            ->assertJsonPath('server_geofence_result', 'INSIDE');

        $event = VendingAttendanceEvent::query()->sole();
        $original = $event->getAttributes();
        $this->assertSame($payload['event_uuid'], $event->event_uuid);
        $this->assertSame($provisioned['device']->id, $event->device_id);
        $this->assertSame($machine->id, $event->vending_machine_id);
        $this->assertSame($assignment->id, $event->employee_machine_assignment_id);
        $this->assertSame('PRIMARY', $event->assignment_type_snapshot);
        $this->assertTrue($event->attendance_allowed_snapshot);
        $this->assertSame($machine->machine_code, $event->machine_code_snapshot);
        $this->assertSame($geofence->version, $event->geofence_version);
        $this->assertSame('50.00', $event->geofence_radius_snapshot);
        $this->assertSame(64, strlen($event->payload_hash));
        $this->assertSame('NOT_USED', $event->biometric_result->value);
        $this->assertSame(42, $event->device_clock_drift_seconds);
        $this->assertSame(
            \Illuminate\Support\Carbon::parse($payload['captured_at'])->utc()->toIso8601String(),
            $event->captured_at_device->utc()->toIso8601String(),
        );
        $this->assertSame(0, $event->sync_delay_seconds);

        $duplicate = $this->signedDeviceRequest(
            'POST',
            '/api/v1/device/attendance/events',
            $payload,
            $provisioned['device']->fresh(),
            $provisioned['credential'],
        )->assertOk()->assertJsonPath('status', 'DUPLICATE');

        $this->assertSame($stored->json('remote_id'), $duplicate->json('remote_id'));
        $this->assertDatabaseCount('vending_attendance_events', 1);
        $this->assertSame($original, $event->fresh()->getAttributes());
        $this->assertDatabaseHas('device_attendance_metrics', [
            'device_id' => $provisioned['device']->id,
            'stored_total' => 1,
            'duplicate_total' => 1,
        ]);
    }

    public function test_reused_uuid_with_different_payload_is_rejected_as_conflict(): void
    {
        $machine = $this->machine('ATT-CONFLICT');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $geofence = $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'ATT-CONFLICT-DEVICE');
        $payload = $this->attendancePayload($machine, $employee, $assignment, $geofence);

        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $payload, $provisioned['device'], $provisioned['credential'])
            ->assertCreated();

        $conflicting = array_replace($payload, ['event_type' => 'CHECK_OUT']);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $conflicting, $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertConflict()
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonPath('error_code', 'EVENT_UUID_CONFLICT');

        $this->assertDatabaseCount('vending_attendance_events', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'attendance.uuid_conflict',
            'auditable_id' => VendingAttendanceEvent::query()->value('id'),
        ]);
        $this->assertDatabaseHas('device_attendance_metrics', [
            'device_id' => $provisioned['device']->id,
            'rejected_total' => 1,
        ]);
    }

    public function test_global_event_uuid_cannot_be_reused_by_another_device(): void
    {
        $firstMachine = $this->machine('ATT-GLOBAL-UUID-1');
        $secondMachine = $this->machine('ATT-GLOBAL-UUID-2');
        $firstEmployee = $this->employee();
        $secondEmployee = $this->employee();
        $firstAssignment = $this->assignment($firstMachine, $firstEmployee);
        $secondAssignment = $this->assignment($secondMachine, $secondEmployee);
        $firstDevice = $this->provisionedDevice($firstMachine, 'ATT-GLOBAL-UUID-DEVICE-1');
        $secondDevice = $this->provisionedDevice($secondMachine, 'ATT-GLOBAL-UUID-DEVICE-2');
        $firstPayload = $this->attendancePayload($firstMachine, $firstEmployee, $firstAssignment);
        $secondPayload = $this->attendancePayload($secondMachine, $secondEmployee, $secondAssignment, null, [
            'event_uuid' => $firstPayload['event_uuid'],
        ]);

        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $firstPayload, $firstDevice['device'], $firstDevice['credential'])
            ->assertCreated();
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $secondPayload, $secondDevice['device'], $secondDevice['credential'])
            ->assertConflict()->assertJsonPath('error_code', 'EVENT_UUID_CONFLICT');

        $this->assertDatabaseCount('vending_attendance_events', 1);
        $this->assertSame($firstDevice['device']->id, VendingAttendanceEvent::query()->value('device_id'));
    }

    public function test_batch_processes_stored_rejected_and_duplicate_results_independently(): void
    {
        $machine = $this->machine('ATT-BATCH');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $geofence = $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'ATT-BATCH-DEVICE');
        $valid = $this->attendancePayload($machine, $employee, $assignment, $geofence);
        $invalidEmployee = $this->attendancePayload($machine, $employee, $assignment, $geofence, [
            'employee_id' => 999999,
        ]);

        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events/batch', [
            'events' => [$valid, $invalidEmployee, $valid],
        ], $provisioned['device'], $provisioned['credential'])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'STORED')
            ->assertJsonPath('results.1.status', 'REJECTED')
            ->assertJsonPath('results.1.error_code', 'INVALID_EMPLOYEE')
            ->assertJsonPath('results.2.status', 'DUPLICATE');

        $this->assertDatabaseCount('vending_attendance_events', 1);
    }

    public function test_batch_limit_is_configurable_and_audited(): void
    {
        config(['vending.attendance.batch_max_events' => 2]);
        $machine = $this->machine('ATT-BATCH-LIMIT');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $provisioned = $this->provisionedDevice($machine, 'ATT-BATCH-LIMIT-DEVICE');
        $events = [
            $this->attendancePayload($machine, $employee, $assignment),
            $this->attendancePayload($machine, $employee, $assignment),
            $this->attendancePayload($machine, $employee, $assignment),
        ];

        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events/batch', ['events' => $events], $provisioned['device'], $provisioned['credential'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonPath('error_code', 'BATCH_LIMIT_EXCEEDED')
            ->assertJsonPath('limit', 2);

        $this->assertDatabaseCount('vending_attendance_events', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'attendance.batch_abuse']);
    }

    public function test_event_model_rejects_mutation_and_deletion(): void
    {
        $machine = $this->machine('ATT-IMMUTABLE');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $provisioned = $this->provisionedDevice($machine, 'ATT-IMMUTABLE-DEVICE');
        $payload = $this->attendancePayload($machine, $employee, $assignment);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $payload, $provisioned['device'], $provisioned['credential'])
            ->assertCreated();
        $event = VendingAttendanceEvent::query()->sole();

        try {
            $event->forceFill(['machine_code_snapshot' => 'MUTATED'])->save();
            $this->fail('Mutation should have been rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Vending attendance evidence is immutable.', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $event->delete();
    }
}
