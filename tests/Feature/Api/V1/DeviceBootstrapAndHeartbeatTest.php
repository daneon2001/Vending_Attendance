<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DeviceStatus;
use App\Services\Vending\DeviceLifecycleService;
use App\Services\Vending\MachineGeofenceService;

class DeviceBootstrapAndHeartbeatTest extends VendingDeviceApiTestCase
{
    public function test_bootstrap_uses_authenticated_device_machine_and_active_geofence(): void
    {
        $machine = $this->machine('BOOT-001');
        $other = $this->machine('BOOT-OTHER');
        $geofence = app(MachineGeofenceService::class)->create($machine, [
            'center_latitude' => 19.4326,
            'center_longitude' => -99.1332,
            'radius_m' => 45,
            'minimum_acceptable_accuracy_m' => 20,
            'tolerance_m' => 3,
            'status' => 'ACTIVE',
        ]);
        $provisioned = $this->provisionedDevice($machine, 'BOOT-DEVICE');

        $response = $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $provisioned['device'], $provisioned['credential'])
            ->assertOk()
            ->assertJsonPath('machine.uuid', $machine->uuid)
            ->assertJsonPath('geofence.uuid', $geofence->uuid)
            ->assertJsonPath('geofence.type', 'CIRCLE')
            ->assertJsonPath('configuration_changed', true)
            ->assertJsonMissing(['uuid' => $other->uuid]);

        $this->assertArrayNotHasKey('credentials', $response->json());
        $this->assertArrayNotHasKey('metadata', $response->json('device'));
    }

    public function test_bootstrap_reports_changed_and_unchanged_configuration_versions(): void
    {
        $machine = $this->machine();
        $provisioned = $this->provisionedDevice($machine, 'VERSION-DEVICE');

        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', ['config_version_applied' => 1], $provisioned['device'], $provisioned['credential'])
            ->assertOk()->assertJsonPath('configuration_changed', false);

        $machine->update(['timezone' => 'America/Cancun']);
        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', ['config_version_applied' => 1], $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertOk()->assertJsonPath('configuration_changed', true)->assertJsonPath('machine.config_version', 2);

        $machine->update(['name' => 'Descriptive only']);
        $this->assertSame(2, $machine->refresh()->config_version);
    }

    public function test_heartbeat_updates_latest_state_and_calculates_clock_drift(): void
    {
        $machine = $this->machine();
        $provisioned = $this->provisionedDevice($machine, 'HEARTBEAT-001');
        config(['vending.device.clock_drift_warning_seconds' => 300]);

        $this->signedDeviceRequest('POST', '/api/v1/device/heartbeat', [
            'app_version' => '1.2.0',
            'platform_version' => '15.1',
            'config_version_applied' => 1,
            'battery_level' => 87.5,
            'storage_free_mb' => 2048,
            'pending_events_count' => 4,
            'device_time' => now()->subMinutes(10)->toIso8601String(),
        ], $provisioned['device'], $provisioned['credential'])
            ->assertOk()
            ->assertJsonPath('clock_drift_warning', true);

        $device = $provisioned['device']->fresh();
        $this->assertNotNull($device->last_seen_at);
        $this->assertSame(1, $device->config_version_applied);
        $this->assertSame(4, $device->pending_events_count);
        $this->assertGreaterThanOrEqual(599, $device->clock_drift_seconds);
        $this->assertDatabaseHas('audit_logs', ['event' => 'device.clock_drift_detected', 'auditable_id' => $device->id]);
    }

    public function test_revoked_device_cannot_send_heartbeat(): void
    {
        $provisioned = $this->provisionedDevice($this->machine(), 'HEARTBEAT-REVOKED');
        app(DeviceLifecycleService::class)->transition($provisioned['device'], DeviceStatus::REVOKED);

        $this->signedDeviceRequest('POST', '/api/v1/device/heartbeat', [
            'device_time' => now()->toIso8601String(),
        ], $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertUnauthorized()->assertJsonPath('error', 'DEVICE_NOT_ACTIVE');
    }
}
