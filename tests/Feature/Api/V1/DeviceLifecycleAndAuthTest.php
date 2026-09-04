<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use App\Services\Vending\DeviceCredentialService;
use App\Services\Vending\DeviceLifecycleService;
use Laravel\Sanctum\Sanctum;

class DeviceLifecycleAndAuthTest extends VendingDeviceApiTestCase
{
    public function test_device_lifecycle_supports_activation_suspension_revocation_and_retirement(): void
    {
        $machine = $this->machine();
        $provisioned = $this->provisionedDevice($machine, 'LIFE-001');
        $device = $provisioned['device'];
        $service = app(DeviceLifecycleService::class);

        $this->assertSame(DeviceStatus::ACTIVE, $device->status);
        $this->assertSame(DeviceStatus::SUSPENDED, $service->transition($device, DeviceStatus::SUSPENDED)->status);
        $this->assertSame(DeviceStatus::ACTIVE, $service->transition($device->fresh(), DeviceStatus::ACTIVE)->status);
        $this->assertSame(DeviceStatus::RETIRED, $service->transition($device->fresh(), DeviceStatus::RETIRED)->status);
        $this->assertNull($device->fresh()->credential_secret);

        $second = $this->provisionedDevice($machine, 'LIFE-002')['device'];
        $this->assertSame(DeviceStatus::REVOKED, $service->transition($second, DeviceStatus::REVOKED)->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'device.revoked', 'auditable_id' => $second->id]);
    }

    public function test_valid_device_authenticates_but_invalid_or_rotated_credential_does_not(): void
    {
        $provisioned = $this->provisionedDevice($this->machine(), 'AUTH-DEVICE');
        $device = $provisioned['device'];

        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $device, $provisioned['credential'])->assertOk();
        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $device, str_repeat('a', 64))->assertUnauthorized()->assertJsonPath('error', 'INVALID_SIGNATURE');

        $rotated = app(DeviceCredentialService::class)->rotate($device);
        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $device->fresh(), $provisioned['credential'])->assertUnauthorized();
        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $device->fresh(), $rotated['credential'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'device.credential.rotated', 'auditable_id' => $device->id]);
    }

    public function test_revoked_device_and_human_sanctum_token_cannot_impersonate_device(): void
    {
        $provisioned = $this->provisionedDevice($this->machine(), 'REVOKED-DEVICE');
        app(DeviceLifecycleService::class)->transition($provisioned['device'], DeviceStatus::REVOKED);

        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertUnauthorized()->assertJsonPath('error', 'DEVICE_NOT_ACTIVE');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/device/bootstrap')->assertUnprocessable()->assertJsonPath('error', 'VALIDATION_FAILED');
    }

    public function test_nonce_replay_and_expired_timestamp_are_rejected(): void
    {
        $provisioned = $this->provisionedDevice($this->machine(), 'REPLAY-AUTH');
        $nonce = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $provisioned['device'], $provisioned['credential'], null, $nonce)->assertOk();
        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $provisioned['device'], $provisioned['credential'], null, $nonce)
            ->assertStatus(409)->assertJsonPath('error', 'NONCE_REPLAY');
        $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', [], $provisioned['device'], $provisioned['credential'], now()->subMinutes(10)->timestamp)
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TIMESTAMP');
    }

    public function test_request_signature_covers_the_query_string(): void
    {
        $provisioned = $this->provisionedDevice($this->machine(), 'QUERY-SIGNATURE');

        $this->signedDeviceRequest(
            'GET',
            '/api/v1/device/bootstrap',
            ['config_version_applied' => 2],
            $provisioned['device'],
            $provisioned['credential'],
            signedRequestTarget: '/api/v1/device/bootstrap?config_version_applied=1',
        )->assertUnauthorized()->assertJsonPath('error', 'INVALID_SIGNATURE');
    }

    public function test_database_allows_only_one_active_vending_device_per_machine(): void
    {
        $machine = $this->machine();
        $first = $this->provisionedDevice($machine, 'PRIMARY-001')['device'];

        $second = new Device([
            'vending_machine_id' => $machine->id,
            'device_serial' => 'PRIMARY-002',
            'status' => DeviceStatus::ACTIVE,
            'shared_secret' => '',
        ]);
        $second->forceFill(['credential_secret' => str_repeat('b', 64)]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $second->save();
    }
}
