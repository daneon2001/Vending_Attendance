<?php

namespace Tests\Feature\Vending;

use App\Enums\DeviceStatus;
use App\Models\MobileRelease;
use App\Models\MobileReleasePolicy;
use App\Models\VendingAttendanceEvent;
use App\Services\Vending\EmployeeManifestService;
use App\Services\Vending\MachineAssignmentService;
use App\Services\Vending\MachineGeofenceService;
use App\Services\Vending\VendingFleetOperationsService;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class PilotOperationsAcceptanceTest extends VendingDeviceApiTestCase
{
    public function test_device_replacement_preserves_history_and_new_device_operates(): void
    {
        $machine = $this->machine('PILOT-REPLACE');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $geofence = $this->geofence($machine);
        $old = $this->provisionedDevice($machine, 'PILOT-HW-A');

        $this->signedDeviceRequest(
            'POST', '/api/v1/device/attendance/events',
            $this->attendancePayload($machine, $employee, $assignment, $geofence),
            $old['device'], $old['credential'],
        )->assertCreated()->assertJsonPath('status', 'STORED');
        $oldEventId = VendingAttendanceEvent::query()->sole()->id;

        $new = $this->provisionedDevice($machine, 'PILOT-HW-B');
        $this->assertSame(DeviceStatus::RETIRED, $old['device']->refresh()->status);
        $this->assertSame(DeviceStatus::ACTIVE, $new['device']->refresh()->status);
        $this->assertDatabaseHas('vending_attendance_events', [
            'id' => $oldEventId, 'device_id' => $old['device']->id,
        ]);

        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $new['device'], $new['credential'])
            ->assertOk()->assertJsonPath('machine.uuid', $machine->uuid);
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/employees', [], $new['device']->fresh(), $new['credential'])
            ->assertOk()->assertJsonCount(1, 'employees');
        $this->signedDeviceRequest(
            'POST', '/api/v1/device/attendance/events',
            $this->attendancePayload($machine, $employee, $assignment, $geofence),
            $new['device']->fresh(), $new['credential'],
        )->assertCreated()->assertJsonPath('status', 'STORED');
        $this->assertDatabaseCount('vending_attendance_events', 2);
    }

    public function test_reassignment_changes_both_machine_desired_states(): void
    {
        $machineA = $this->machine('PILOT-ASSIGN-A');
        $machineB = $this->machine('PILOT-ASSIGN-B');
        $employee = $this->employee();
        $assignmentA = $this->assignment($machineA, $employee);
        $service = app(EmployeeManifestService::class);
        $versionABefore = $machineA->fresh()->employee_manifest_version;
        $versionBBefore = $machineB->fresh()->employee_manifest_version;

        $this->assertCount(1, $service->snapshot($machineA->fresh())['employees']);
        $this->assertCount(0, $service->snapshot($machineB->fresh())['employees']);

        app(MachineAssignmentService::class)->revoke($assignmentA, null, 'Pilot reassignment');
        $this->assignment($machineB, $employee);
        $snapshotA = $service->snapshot($machineA->fresh());
        $snapshotB = $service->snapshot($machineB->fresh());

        $this->assertCount(0, $snapshotA['employees']);
        $this->assertCount(1, $snapshotB['employees']);
        $this->assertSame((string) $employee->id, $snapshotB['employees'][0]['employee_id']);
        $this->assertGreaterThan($versionABefore, $machineA->fresh()->employee_manifest_version);
        $this->assertGreaterThan($versionBBefore, $machineB->fresh()->employee_manifest_version);
    }

    public function test_geofence_update_moves_device_pending_to_synced_after_ack(): void
    {
        $machine = $this->machine('PILOT-GEOFENCE');
        $first = $this->geofence($machine);
        $device = $this->provisionedDevice($machine, 'PILOT-GEOFENCE-DEVICE');
        $initial = $this->signedDeviceRequest(
            'GET', '/api/v1/device/manifests/configuration', [], $device['device'], $device['credential'],
        )->json();
        $this->ackConfiguration($device['device']->fresh(), $device['credential'], $initial)->assertOk();

        $versionBefore = $machine->fresh()->config_version;
        $second = app(MachineGeofenceService::class)->create($machine->fresh(), [
            'center_latitude' => 19.4330,
            'center_longitude' => -99.1335,
            'radius_m' => 75,
            'minimum_acceptable_accuracy_m' => 35,
            'tolerance_m' => 10,
            'status' => 'ACTIVE',
            'source' => 'TEST',
        ]);
        $this->assertGreaterThan($versionBefore, $machine->fresh()->config_version);
        $this->assertSame('SUPERSEDED', $first->fresh()->status->value);

        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $device['device']->fresh(), $device['credential'])
            ->assertOk()->assertJsonPath('configuration.state', 'PENDING');
        $updated = $this->signedDeviceRequest(
            'GET', '/api/v1/device/manifests/configuration', [], $device['device']->fresh(), $device['credential'],
        )->assertOk()->assertJsonPath('geofence.version', $second->version)->json();
        $this->ackConfiguration($device['device']->fresh(), $device['credential'], $updated)->assertOk();
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $device['device']->fresh(), $device['credential'])
            ->assertOk()->assertJsonPath('configuration.state', 'SYNCED');
    }

    public function test_derived_alerts_appear_and_disappear_after_resolution(): void
    {
        config([
            'vending.device.health.degraded_after_seconds' => 180,
            'vending.device.health.offline_after_seconds' => 600,
            'vending.device.health.clock_drift_seconds' => 300,
            'vending.device.health.pending_events_count' => 100,
            'vending.device.health.storage_free_mb' => 256,
        ]);
        $machine = $this->machine('PILOT-ALERTS');
        $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'PILOT-ALERT-DEVICE');
        $device = $provisioned['device'];
        $device->forceFill([
            'last_heartbeat_at' => now(), 'last_seen_at' => now(),
            'config_version_applied' => $machine->fresh()->config_version,
            'employee_manifest_version_applied' => $machine->fresh()->employee_manifest_version,
            'storage_free_mb' => 1024, 'pending_events_count' => 0, 'clock_drift_seconds' => 0,
        ])->save();
        $operations = app(VendingFleetOperationsService::class);
        $this->assertSame([], $operations->dashboard()['alerts']);

        foreach ([
            ['last_heartbeat_at', now()->subMinutes(20), now(), 'HEARTBEAT_EXPIRED'],
            ['clock_drift_seconds', 301, 0, 'CLOCK_DRIFT'],
            ['pending_events_count', 100, 0, 'OUTBOX_PRESSURE'],
            ['storage_free_mb', 128, 1024, 'LOW_STORAGE'],
        ] as [$field, $bad, $good, $reason]) {
            $device->forceFill([$field => $bad])->save();
            $this->assertStringContainsString($reason, $operations->dashboard()['alerts'][0]['message']);
            $device->forceFill([$field => $good])->save();
            $this->assertSame([], $operations->dashboard()['alerts']);
        }

        $machine->refresh();
        $machine->forceFill(['config_version' => $machine->config_version + 4])->saveQuietly();
        $this->assertStringContainsString('MANIFEST_STALE', $operations->dashboard()['alerts'][0]['message']);
        $device->forceFill(['config_version_applied' => $machine->fresh()->config_version])->save();
        $this->assertSame([], $operations->dashboard()['alerts']);

        $release = MobileRelease::query()->create([
            'platform' => 'ANDROID', 'channel' => 'PRODUCTION', 'version' => '2.0.0',
            'build_number' => 20, 'status' => 'PUBLISHED', 'mandatory' => true,
            'rollout_percentage' => 100, 'artifact_url' => 'https://releases.example.test/app.apk',
            'artifact_sha256' => str_repeat('a', 64), 'released_at' => now(),
        ]);
        MobileReleasePolicy::query()->create([
            'platform' => 'ANDROID', 'channel' => 'PRODUCTION',
            'current_release_id' => $release->id, 'recommended_release_id' => $release->id,
        ]);
        $this->assertStringContainsString('APP_UPDATE_REQUIRED', $operations->dashboard()['alerts'][0]['message']);
        $device->forceFill(['app_version' => '2.0.0', 'app_build_number' => 20])->save();
        $this->assertSame([], $operations->dashboard()['alerts']);

        $machine->forceFill(['geofence_review_required' => true])->saveQuietly();
        $this->assertSame('GEOFENCE_REVIEW', $operations->dashboard()['alerts'][0]['type']);
        $machine->forceFill(['geofence_review_required' => false])->saveQuietly();
        $this->assertSame([], $operations->dashboard()['alerts']);
    }

    private function ackConfiguration($device, string $credential, array $manifest)
    {
        return $this->signedDeviceRequest('POST', '/api/v1/device/manifests/ack', [
            'manifest_type' => 'CONFIGURATION',
            'manifest_version' => $manifest['manifest_version'],
            'manifest_hash' => $manifest['manifest_hash'],
            'applied_at' => now()->toIso8601String(),
            'status' => 'APPLIED',
        ], $device, $credential, null, (string) Str::uuid());
    }
}
