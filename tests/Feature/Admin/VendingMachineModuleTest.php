<?php

namespace Tests\Feature\Admin;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceProvisioningToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VendingMachineModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_open_catalog_and_machine_detail(): void
    {
        $user = $this->authorizedUser();
        $machine = VendingMachine::query()->create([
            'machine_code' => 'WEB-001',
            'name' => 'Máquina web',
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'timezone' => 'America/Mexico_City',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user)->get(route('vending-machines.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendingMachines/Index')
                ->has('machines.data', 1));

        $this->actingAs($user)->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendingMachines/Show')
                ->where('machine.uuid', $machine->uuid));
    }

    public function test_user_without_vending_permission_cannot_open_catalog(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('vending-machines.index'))
            ->assertForbidden();
    }

    public function test_admin_can_generate_and_revoke_single_display_provisioning_token(): void
    {
        $user = $this->authorizedUser();
        $machine = $this->machine('WEB-TOKEN');

        $response = $this->actingAs($user)->postJson(
            route('vending-machines.provisioning-tokens.store', $machine),
            ['expires_in_minutes' => 30],
        )->assertCreated()->assertJsonStructure(['token', 'token_uuid', 'expires_at']);

        $plainToken = (string) $response->json('token');
        $token = DeviceProvisioningToken::query()->where('uuid', $response->json('token_uuid'))->firstOrFail();
        $this->assertNotSame($plainToken, $token->getRawOriginal('token_hash'));

        $this->actingAs($user)->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('machine.provisioning_tokens.0.token_hash'));

        $this->actingAs($user)->patch(route('vending-machines.provisioning-tokens.revoke', [$machine, $token]), [
            'reason' => 'Cancelled by test',
        ])->assertRedirect();
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'device.provisioning_token.revoked', 'auditable_id' => $token->id]);
    }

    public function test_admin_can_suspend_revoke_and_retire_devices_without_deleting_history(): void
    {
        $user = $this->authorizedUser();
        $machine = $this->machine('WEB-DEVICE');
        $device = $this->device($machine, 'WEB-DEV-1');

        $this->actingAs($user)->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machine.devices.0.manifest_sync.configuration.server_version', 1)
                ->where('machine.devices.0.manifest_sync.configuration.applied_version', null)
                ->where('machine.devices.0.manifest_sync.employees.server_version', 1)
                ->where('machine.devices.0.manifest_sync.sync_state', 'PENDING'));

        $this->actingAs($user)->patch(route('vending-machines.devices.status', [$machine, $device]), [
            'status' => DeviceStatus::SUSPENDED->value,
        ])->assertRedirect();
        $this->assertSame(DeviceStatus::SUSPENDED, $device->fresh()->status);

        $this->actingAs($user)->patch(route('vending-machines.devices.status', [$machine, $device]), [
            'status' => DeviceStatus::REVOKED->value,
        ])->assertRedirect();
        $this->assertSame(DeviceStatus::REVOKED, $device->fresh()->status);

        $retired = $this->device($machine, 'WEB-DEV-2');
        $this->actingAs($user)->patch(route('vending-machines.devices.status', [$machine, $retired]), [
            'status' => DeviceStatus::RETIRED->value,
        ])->assertRedirect();
        $this->assertSame(DeviceStatus::RETIRED, $retired->fresh()->status);
        $this->assertSame(2, $machine->devices()->count());
    }

    private function machine(string $code): VendingMachine
    {
        return VendingMachine::query()->create([
            'machine_code' => $code,
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'timezone' => 'America/Mexico_City',
            'status' => 'ACTIVE',
        ]);
    }

    private function device(VendingMachine $machine, string $serial): Device
    {
        $device = new Device([
            'vending_machine_id' => $machine->id,
            'device_serial' => $serial,
            'status' => DeviceStatus::ACTIVE,
            'shared_secret' => '',
        ]);
        $device->forceFill(['credential_secret' => str_repeat('c', 64)]);
        $device->save();

        return $device;
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'vending-web-admin']);
        $role->permissions()->attach(
            Permission::query()->where('module', 'vending_machines')->pluck('id')
        );
        $user->roles()->attach($role);

        return $user;
    }
}
