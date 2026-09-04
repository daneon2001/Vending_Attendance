<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use App\Services\Vending\DeviceLifecycleService;
use App\Services\Vending\MachineGeofenceService;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

class DeviceManifestApiTest extends VendingDeviceApiTestCase
{
    public function test_configuration_manifest_uses_authenticated_device_machine_and_is_deterministic(): void
    {
        $machine = $this->machine('EDGE-CONFIG');
        $other = $this->machine('EDGE-OTHER');
        app(MachineGeofenceService::class)->create($machine, [
            'center_latitude' => 19.4326,
            'center_longitude' => -99.1332,
            'radius_m' => 50,
            'minimum_acceptable_accuracy_m' => 30,
            'tolerance_m' => 10,
            'status' => 'ACTIVE',
        ]);
        $provisioned = $this->provisionedDevice($machine, 'EDGE-CONFIG-DEVICE');

        $first = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/configuration', [], $provisioned['device'], $provisioned['credential'])
            ->assertOk()
            ->assertJsonPath('manifest_type', 'MACHINE_CONFIGURATION')
            ->assertJsonPath('machine.uuid', $machine->uuid)
            ->assertJsonPath('geofence.radius_m', 50);
        $second = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/configuration', [], $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertOk();

        $this->assertSame($first->json('manifest_hash'), $second->json('manifest_hash'));
        $this->assertSame(64, strlen((string) $first->json('manifest_hash')));
        $this->assertNotSame($other->uuid, $first->json('machine.uuid'));
        $this->assertArrayNotHasKey('address_line', $first->json('machine'));
        $this->assertNull($provisioned['device']->fresh()->config_version_applied, 'Downloading is not an ACK.');
    }

    public function test_employee_manifest_supports_full_snapshot_and_known_version_without_cross_machine_data(): void
    {
        $machine = $this->machine('EDGE-EMPLOYEES');
        $otherMachine = $this->machine('EDGE-EMPLOYEES-OTHER');
        $employee = $this->employee(['full_name' => 'Authorized Edge Employee']);
        $otherEmployee = $this->employee(['full_name' => 'Other Machine Employee']);
        $this->assignment($machine, $employee);
        $this->assignment($otherMachine, $otherEmployee);
        $provisioned = $this->provisionedDevice($machine, 'EDGE-EMPLOYEE-DEVICE');

        $snapshot = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/employees', [], $provisioned['device'], $provisioned['credential'])
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonCount(1, 'employees')
            ->assertJsonPath('employees.0.employee_id', (string) $employee->id);
        $version = (int) $snapshot->json('manifest_version');

        $this->signedDeviceRequest(
            'GET',
            '/api/v1/device/manifests/employees',
            ['known_version' => $version],
            $provisioned['device']->fresh(),
            $provisioned['credential'],
        )->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonMissingPath('employees');
    }

    public function test_status_moves_from_pending_to_synced_only_after_explicit_acks(): void
    {
        $machine = $this->machine('EDGE-STATUS');
        $this->assignment($machine, $this->employee());
        $provisioned = $this->provisionedDevice($machine, 'EDGE-STATUS-DEVICE');
        $device = $provisioned['device'];

        $status = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $device, $provisioned['credential'])
            ->assertOk()
            ->assertJsonPath('configuration.changed', true)
            ->assertJsonPath('employees.changed', true)
            ->assertJsonPath('biometrics.supported', false)
            ->assertJsonPath('sync_state', 'PENDING');

        $configuration = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/configuration', [], $device->fresh(), $provisioned['credential'])->json();
        $employees = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/employees', [], $device->fresh(), $provisioned['credential'])->json();
        $this->ack($device->fresh(), $provisioned['credential'], 'CONFIGURATION', $configuration)->assertOk();
        $this->ack($device->fresh(), $provisioned['credential'], 'EMPLOYEES', $employees)->assertOk();

        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $device->fresh(), $provisioned['credential'])
            ->assertOk()
            ->assertJsonPath('configuration.changed', false)
            ->assertJsonPath('employees.changed', false)
            ->assertJsonPath('sync_state', 'SYNCED');

        $machine->update(['timezone' => 'America/Cancun']);
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $device->fresh(), $provisioned['credential'])
            ->assertOk()
            ->assertJsonPath('configuration.changed', true)
            ->assertJsonPath('configuration.state', 'PENDING');

        $this->assertNull($status->json('configuration.applied_version'));
        $this->assertSame($configuration['manifest_version'], $device->fresh()->config_version_applied);
        $this->assertSame($employees['manifest_version'], $device->fresh()->employee_manifest_version_applied);
    }

    public function test_ack_is_idempotent_rejects_hash_mismatch_and_never_moves_version_backwards(): void
    {
        $machine = $this->machine('EDGE-ACK');
        $this->assignment($machine, $this->employee());
        $provisioned = $this->provisionedDevice($machine, 'EDGE-ACK-DEVICE');
        $device = $provisioned['device'];
        $snapshot = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/employees', [], $device, $provisioned['credential'])->json();

        $this->ack($device->fresh(), $provisioned['credential'], 'EMPLOYEES', $snapshot)
            ->assertOk()->assertJsonPath('duplicate', false);
        $this->ack($device->fresh(), $provisioned['credential'], 'EMPLOYEES', $snapshot)
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assignment($machine, $this->employee());
        $current = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/employees', [], $device->fresh(), $provisioned['credential'])->json();
        $this->assertGreaterThan($snapshot['manifest_version'], $current['manifest_version']);

        $this->ack($device->fresh(), $provisioned['credential'], 'EMPLOYEES', $snapshot)
            ->assertOk()->assertJsonPath('stale', true);
        $this->assertSame($snapshot['manifest_version'], $device->fresh()->employee_manifest_version_applied);
        $this->assertDatabaseHas('audit_logs', ['event' => 'manifest.stale_ack', 'auditable_id' => $device->id]);

        $invalid = $current;
        $invalid['manifest_hash'] = str_repeat('0', 64);
        $this->ack($device->fresh(), $provisioned['credential'], 'EMPLOYEES', $invalid)
            ->assertUnprocessable()->assertJsonPath('code', 'MANIFEST_HASH_MISMATCH');
        $this->assertDatabaseHas('audit_logs', ['event' => 'manifest.hash_mismatch', 'auditable_id' => $device->id]);
        $this->assertSame($snapshot['manifest_version'], $device->fresh()->employee_manifest_version_applied);
    }

    public function test_failed_ack_sets_error_without_advancing_and_valid_applied_ack_recovers(): void
    {
        $machine = $this->machine('EDGE-FAILED-ACK');
        $this->assignment($machine, $this->employee());
        $provisioned = $this->provisionedDevice($machine, 'EDGE-FAILED-DEVICE');
        $device = $provisioned['device'];
        $snapshot = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/employees', [], $device, $provisioned['credential'])->json();

        $this->ack($device->fresh(), $provisioned['credential'], 'EMPLOYEES', $snapshot, 'FAILED', [
            'error_code' => 'LOCAL_WRITE_FAILED',
            'error_message' => '<b>Storage transaction failed</b>',
        ])->assertOk()->assertJsonPath('ack_status', 'FAILED');

        $this->assertNull($device->fresh()->employee_manifest_version_applied);
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $device->fresh(), $provisioned['credential'])
            ->assertOk()->assertJsonPath('employees.state', 'ERROR')->assertJsonPath('sync_state', 'ERROR');
        $this->assertDatabaseHas('device_manifest_states', [
            'device_id' => $device->id,
            'manifest_type' => 'EMPLOYEES',
            'last_error_message' => 'Storage transaction failed',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'manifest.ack_failed', 'auditable_id' => $device->id]);

        $this->ack($device->fresh(), $provisioned['credential'], 'EMPLOYEES', $snapshot)
            ->assertOk()->assertJsonPath('ack_status', 'APPLIED');
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $device->fresh(), $provisioned['credential'])
            ->assertOk()->assertJsonPath('employees.state', 'SYNCED');
    }

    public function test_manifest_endpoints_reject_revoked_human_and_replayed_authentication(): void
    {
        $provisioned = $this->provisionedDevice($this->machine('EDGE-SECURITY'), 'EDGE-SECURITY-DEVICE');
        $nonce = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $configuration = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/configuration', [], $provisioned['device'], $provisioned['credential'])->json();

        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $provisioned['device'], $provisioned['credential'], null, $nonce)
            ->assertOk();
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $provisioned['device'], $provisioned['credential'], null, $nonce)
            ->assertStatus(409)->assertJsonPath('error', 'NONCE_REPLAY');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/device/manifests/status')->assertUnprocessable();
        $this->postJson('/api/v1/device/manifests/ack', [
            'manifest_type' => 'CONFIGURATION',
            'manifest_version' => $configuration['manifest_version'],
            'manifest_hash' => $configuration['manifest_hash'],
            'status' => 'APPLIED',
        ])->assertUnprocessable();

        app(DeviceLifecycleService::class)->transition($provisioned['device']->fresh(), DeviceStatus::REVOKED);
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertUnauthorized()->assertJsonPath('error', 'DEVICE_NOT_ACTIVE');
        $this->ack($provisioned['device']->fresh(), $provisioned['credential'], 'CONFIGURATION', $configuration)
            ->assertUnauthorized()->assertJsonPath('error', 'DEVICE_NOT_ACTIVE');
    }

    public function test_manifest_status_has_its_own_rate_limit(): void
    {
        config(['vending.manifests.rate_limits.status_per_minute' => 2]);
        $provisioned = $this->provisionedDevice($this->machine('EDGE-RATE'), 'EDGE-RATE-DEVICE');
        RateLimiter::clear('vending-manifest-status:'.$provisioned['device']->uuid);

        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $provisioned['device'], $provisioned['credential'])->assertOk();
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $provisioned['device']->fresh(), $provisioned['credential'])->assertOk();
        $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $provisioned['device']->fresh(), $provisioned['credential'])->assertTooManyRequests();
    }

    private function ack(
        Device $device,
        string $credential,
        string $type,
        array $snapshot,
        string $status = 'APPLIED',
        array $extra = [],
    ) {
        return $this->signedDeviceRequest('POST', '/api/v1/device/manifests/ack', array_merge([
            'manifest_type' => $type,
            'manifest_version' => $snapshot['manifest_version'],
            'manifest_hash' => $snapshot['manifest_hash'],
            'applied_at' => now()->toIso8601String(),
            'status' => $status,
        ], $extra), $device, $credential);
    }
}
