<?php

namespace Tests\Feature\Admin;

use App\Enums\Vending\VendingCatalogSource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SybiVendingSourceRecord;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SybiVendingAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sybi.vending.url', 'https://sybi.invalid/v1/public/sucursales-vending');
        config()->set('sybi.vending.token', 'test-only-random-value');
    }

    public function test_catalog_exposes_sanitized_integration_state_and_source_filters(): void
    {
        $user = $this->authorizedUser();
        VendingMachine::query()->create([
            'sybi_id' => '701',
            'machine_code' => 'VM-SYBI-701',
            'source' => VendingCatalogSource::SYBI,
            'timezone' => 'America/Mexico_City',
            'status' => 'DRAFT',
        ]);

        $this->actingAs($user)
            ->get(route('vending-machines.index', ['source' => 'SYBI']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendingMachines/Index')
                ->where('filters.source', 'SYBI')
                ->where('sybiIntegration.configured', true)
                ->where('sybiIntegration.automatic_sync_enabled', false)
                ->has('machines.data', 1)
                ->missing('sybiIntegration.token'));
    }

    public function test_manage_permission_can_trigger_server_side_sync_and_get_summary(): void
    {
        $user = $this->authorizedUser();
        Http::fake(['*' => Http::response([
            'ok' => true,
            'total' => 1,
            'data' => [$this->record()],
        ])]);

        $this->actingAs($user)
            ->post(route('vending-machines.sybi-sync'))
            ->assertRedirect()
            ->assertSessionHas('sybi_sync_result.ok', true)
            ->assertSessionHas('sybi_sync_result.created', 1);

        $this->assertDatabaseHas('vending_machines', ['sybi_id' => '701', 'source' => 'SYBI']);
        Http::assertSentCount(1);
    }

    public function test_source_catalog_view_exposes_validation_and_promotion_without_credentials(): void
    {
        $user = $this->authorizedUser();
        SybiVendingSourceRecord::query()->create([
            'sybi_id' => 702,
            'identificador_vending' => 'VM-SYBI-702',
            'latitude' => 0,
            'longitude' => 0,
            'source_status' => 'PRESENT',
            'validation_status' => 'INCOMPLETE_LOCATION',
            'validation_codes' => ['ZERO_COORDINATES'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'payload_hash' => hash('sha256', 'source-test'),
        ]);

        $this->actingAs($user)
            ->get(route('vending-machines.index', ['catalog_view' => 'sybi', 'validation_status' => 'INCOMPLETE_LOCATION']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendingMachines/Index')
                ->where('filters.catalog_view', 'sybi')
                ->where('filters.validation_status', 'INCOMPLETE_LOCATION')
                ->has('sourceRecords.data', 1)
                ->where('sourceRecords.data.0.sybi_id', 702)
                ->where('sourceRecords.data.0.validation_codes.0', 'ZERO_COORDINATES')
                ->missing('sybiIntegration.token'));
    }

    public function test_user_without_manage_permission_cannot_trigger_sync(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('vending-machines.sybi-sync'))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_sybi_owned_fields_are_read_only_but_local_operational_fields_can_change(): void
    {
        $user = $this->authorizedUser();
        $machine = VendingMachine::query()->create([
            'sybi_id' => '701',
            'machine_code' => 'VM-SYBI-701',
            'source' => VendingCatalogSource::SYBI,
            'name' => 'SYBI name',
            'latitude' => 19.4,
            'longitude' => -99.1,
            'coordinate_source' => 'SYBI',
            'timezone' => 'America/Mexico_City',
            'status' => 'DRAFT',
        ]);

        $base = [
            'sybi_id' => '701',
            'machine_code' => 'VM-SYBI-701',
            'name' => 'SYBI name',
            'latitude' => 19.4,
            'longitude' => -99.1,
            'coordinate_source' => 'SYBI',
            'timezone' => 'America/Tijuana',
            'status' => 'ACTIVE',
        ];

        $this->actingAs($user)
            ->put(route('vending-machines.update', $machine), array_merge($base, ['machine_code' => 'MANUAL-CHANGE']))
            ->assertSessionHasErrors('machine_code');

        $this->actingAs($user)
            ->put(route('vending-machines.update', $machine), $base)
            ->assertRedirect();

        $machine->refresh();
        $this->assertSame('VM-SYBI-701', $machine->machine_code);
        $this->assertSame('America/Tijuana', $machine->timezone);
        $this->assertSame('ACTIVE', $machine->status->value);
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'sybi-vending-admin']);
        $role->permissions()->attach(
            Permission::query()->where('module', 'vending_machines')->pluck('id')
        );
        $user->roles()->attach($role);

        return $user;
    }

    private function record(): array
    {
        return [
            'id_sucursal' => 701,
            'identificador_vending' => 'VM-SYBI-701',
            'nombre_sucursal' => 'Vending Norte',
            'ubicacion' => [
                'calle' => 'Calle 1',
                'colonia' => 'Centro',
                'codigo_postal' => '06000',
                'id_ciudad' => 10,
                'id_estado' => 9,
                'direccion_completa' => 'Calle 1, Centro',
            ],
            'latitud' => 19.4,
            'longitud' => -99.1,
        ];
    }
}
