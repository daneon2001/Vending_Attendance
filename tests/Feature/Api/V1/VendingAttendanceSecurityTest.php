<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DeviceStatus;
use App\Models\User;
use App\Services\Vending\DeviceLifecycleService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class VendingAttendanceSecurityTest extends VendingDeviceApiTestCase
{
    public function test_nonce_replay_expired_signature_and_payload_tampering_are_rejected(): void
    {
        $machine = $this->machine('ATT-SECURITY');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $provisioned = $this->provisionedDevice($machine, 'ATT-SECURITY-DEVICE');
        $payload = $this->attendancePayload($machine, $employee, $assignment);
        $nonce = (string) Str::uuid();

        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $payload, $provisioned['device'], $provisioned['credential'], null, $nonce)
            ->assertCreated();
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $payload, $provisioned['device']->fresh(), $provisioned['credential'], null, $nonce)
            ->assertConflict()->assertJsonPath('error', 'NONCE_REPLAY');

        $newPayload = $this->attendancePayload($machine, $employee, $assignment);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $newPayload, $provisioned['device']->fresh(), $provisioned['credential'], now()->subMinutes(6)->timestamp)
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TIMESTAMP');

        $signedPayload = $this->attendancePayload($machine, $employee, $assignment);
        $tamperedPayload = array_replace($signedPayload, ['event_type' => 'CHECK_OUT']);
        $this->signedDeviceRequest(
            'POST',
            '/api/v1/device/attendance/events',
            $tamperedPayload,
            $provisioned['device']->fresh(),
            $provisioned['credential'],
            null,
            null,
            null,
            $signedPayload,
        )->assertUnauthorized()->assertJsonPath('error', 'INVALID_SIGNATURE');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'attendance.invalid_signature',
            'auditable_id' => $provisioned['device']->id,
        ]);

        $unknownDevice = $provisioned['device']->replicate();
        $unknownDevice->uuid = (string) Str::uuid();
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $newPayload, $unknownDevice, $provisioned['credential'])
            ->assertUnauthorized()->assertJsonPath('error', 'DEVICE_NOT_ACTIVE');
        $this->assertDatabaseHas('audit_logs', ['event' => 'attendance.invalid_device']);
    }

    public function test_revoked_device_and_human_authentication_cannot_submit_events(): void
    {
        $machine = $this->machine('ATT-SECURITY-AUTH');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $provisioned = $this->provisionedDevice($machine, 'ATT-SECURITY-AUTH-DEVICE');
        $payload = $this->attendancePayload($machine, $employee, $assignment);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/device/attendance/events', $payload)
            ->assertUnprocessable()->assertJsonPath('error', 'VALIDATION_FAILED');

        app(DeviceLifecycleService::class)->transition($provisioned['device']->fresh(), DeviceStatus::REVOKED);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $payload, $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertUnauthorized()->assertJsonPath('error', 'DEVICE_NOT_ACTIVE');

        $this->assertDatabaseCount('vending_attendance_events', 0);
    }

    public function test_device_and_machine_identity_cannot_be_overridden_by_payload(): void
    {
        $machine = $this->machine('ATT-SECURITY-MACHINE');
        $otherMachine = $this->machine('ATT-SECURITY-MACHINE-OTHER');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $provisioned = $this->provisionedDevice($otherMachine, 'ATT-SECURITY-MACHINE-DEVICE');
        $payload = $this->attendancePayload($machine, $employee, $assignment, null, [
            'machine_uuid' => $machine->uuid,
            'vending_machine_id' => $machine->id,
            'device_uuid' => $provisioned['device']->uuid,
        ]);

        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $payload, $provisioned['device'], $provisioned['credential'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonPath('error_code', 'INVALID_EVENT');

        $clean = $this->attendancePayload($machine, $employee, $assignment);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $clean, $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertCreated()
            ->assertJsonPath('authorization_result', 'DENIED')
            ->assertJsonPath('authorization_reason', 'ASSIGNMENT_MACHINE_MISMATCH');

        $this->assertDatabaseHas('vending_attendance_events', [
            'event_uuid' => $clean['event_uuid'],
            'device_id' => $provisioned['device']->id,
            'vending_machine_id' => $otherMachine->id,
        ]);
    }

    public function test_single_and_batch_endpoints_have_independent_rate_limits(): void
    {
        config([
            'vending.attendance.rate_limits.single_per_minute' => 1,
            'vending.attendance.rate_limits.batch_per_minute' => 1,
        ]);
        $machine = $this->machine('ATT-SECURITY-RATE');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $provisioned = $this->provisionedDevice($machine, 'ATT-SECURITY-RATE-DEVICE');
        RateLimiter::clear('vending-attendance-single:'.$provisioned['device']->uuid);
        RateLimiter::clear('vending-attendance-batch:'.$provisioned['device']->uuid);

        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $this->attendancePayload($machine, $employee, $assignment), $provisioned['device'], $provisioned['credential'])
            ->assertCreated();
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $this->attendancePayload($machine, $employee, $assignment), $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertTooManyRequests();

        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events/batch', [
            'events' => [$this->attendancePayload($machine, $employee, $assignment)],
        ], $provisioned['device']->fresh(), $provisioned['credential'])->assertOk();
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events/batch', [
            'events' => [$this->attendancePayload($machine, $employee, $assignment)],
        ], $provisioned['device']->fresh(), $provisioned['credential'])->assertTooManyRequests();
    }
}
