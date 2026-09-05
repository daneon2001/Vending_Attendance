<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DeviceStatus;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\Vending\DeviceProvisioningTokenService;
use Illuminate\Support\Facades\DB;

class DeviceProvisioningTest extends VendingDeviceApiTestCase
{
    public function test_capacitor_localhost_origin_can_provision_without_web_csrf(): void
    {
        $machine = $this->machine();
        $token = $this->provisioningToken($machine);

        $this->withHeader('Origin', 'https://localhost')
            ->postJson('/api/v1/device/provision', [
                'provisioning_token' => $token['plain_token'],
                'device_serial' => 'CAPACITOR-001',
                'platform' => 'android',
            ])
            ->assertCreated()
            ->assertJsonPath('machine.uuid', $machine->uuid);
    }

    public function test_valid_single_use_token_provisions_active_device_and_returns_credential_once(): void
    {
        $machine = $this->machine();
        $token = $this->provisioningToken($machine);
        $response = $this->postJson('/api/v1/device/provision', [
            'provisioning_token' => $token['plain_token'],
            'device_serial' => 'PROV-001',
            'platform' => 'android',
            'app_version' => '1.0.0',
        ])->assertCreated()
            ->assertJsonPath('device.status', DeviceStatus::ACTIVE->value)
            ->assertJsonPath('machine.uuid', $machine->uuid)
            ->assertJsonPath('credentials.scheme', 'HMAC-SHA256');

        $device = Device::query()->where('uuid', $response->json('device.uuid'))->firstOrFail();
        $plainCredential = (string) $response->json('credentials.credential');
        $storedCiphertext = (string) DB::table('devices')->where('id', $device->id)->value('credential_secret');

        $this->assertSame(64, strlen($plainCredential));
        $this->assertNotSame($plainCredential, $storedCiphertext);
        $this->assertSame($plainCredential, $device->credential_secret);
        $this->assertSame($machine->id, $device->active_vending_machine_id);
        $this->assertNotNull($token['token']->fresh()->used_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'device.provisioned', 'auditable_id' => $device->id]);
        $this->assertStringNotContainsString($plainCredential, json_encode(
            AuditLog::query()->where('event', 'device.provisioned')->firstOrFail()->metadata
        ));
    }

    public function test_expired_used_and_revoked_tokens_are_rejected(): void
    {
        $machine = $this->machine();
        $expired = $this->provisioningToken($machine, now()->subMinute());
        $this->postJson('/api/v1/device/provision', [
            'provisioning_token' => $expired['plain_token'], 'device_serial' => 'EXP-001',
        ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_PROVISIONING_TOKEN');

        $used = $this->provisioningToken($machine);
        $payload = ['provisioning_token' => $used['plain_token'], 'device_serial' => 'USED-001'];
        $this->postJson('/api/v1/device/provision', $payload)->assertCreated();
        $this->postJson('/api/v1/device/provision', $payload)
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_PROVISIONING_TOKEN');

        $revoked = $this->provisioningToken($machine);
        app(DeviceProvisioningTokenService::class)->revoke($revoked['token'], null, 'Cancelled');
        $this->postJson('/api/v1/device/provision', [
            'provisioning_token' => $revoked['plain_token'], 'device_serial' => 'REV-001',
        ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_PROVISIONING_TOKEN');
    }

    public function test_token_replay_cannot_create_a_second_device(): void
    {
        $machine = $this->machine();
        $token = $this->provisioningToken($machine);
        $first = ['provisioning_token' => $token['plain_token'], 'device_serial' => 'REPLAY-001'];
        $second = ['provisioning_token' => $token['plain_token'], 'device_serial' => 'REPLAY-002'];

        $this->postJson('/api/v1/device/provision', $first)->assertCreated();
        $this->postJson('/api/v1/device/provision', $second)->assertUnauthorized();
        $this->assertSame(1, Device::query()->where('vending_machine_id', $machine->id)->count());
    }

    public function test_serial_bound_to_another_machine_is_rejected(): void
    {
        $firstMachine = $this->machine('MACHINE-A');
        $secondMachine = $this->machine('MACHINE-B');
        Device::query()->create([
            'vending_machine_id' => $firstMachine->id,
            'device_serial' => 'CROSS-001',
            'status' => DeviceStatus::PENDING,
            'shared_secret' => '',
        ]);
        $token = $this->provisioningToken($secondMachine);

        $this->postJson('/api/v1/device/provision', [
            'provisioning_token' => $token['plain_token'],
            'device_serial' => 'CROSS-001',
        ])->assertStatus(409)->assertJsonPath('code', 'DEVICE_MACHINE_MISMATCH');
        $this->assertNull($token['token']->fresh()->used_at);
    }

    public function test_new_hardware_retires_previous_active_device_without_changing_machine_identity(): void
    {
        $machine = $this->machine();
        $old = $this->provisionedDevice($machine, 'HW-OLD')['device'];
        $new = $this->provisionedDevice($machine, 'HW-NEW')['device'];

        $this->assertSame(DeviceStatus::RETIRED, $old->refresh()->status);
        $this->assertSame(DeviceStatus::ACTIVE, $new->refresh()->status);
        $this->assertSame($machine->id, $new->vending_machine_id);
        $this->assertSame(1, $machine->devices()->where('status', DeviceStatus::ACTIVE->value)->count());
    }
}
