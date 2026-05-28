<?php

namespace Tests\Feature\Admin;

use App\Actions\SyncPermissionCatalog;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnitModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SyncPermissionCatalog::run();
    }

    public function test_authorized_user_can_view_units_page(): void
    {
        $company = Company::query()->create([
            'name' => 'Medicallife',
            'code' => 'MLF',
            'status' => 1,
        ]);

        Unit::query()->create([
            'company_id' => $company->id,
            'name' => 'Unidad Matriz',
            'code' => 'MAT',
            'status' => 1,
            'timezone' => 'America/Mexico_City',
        ]);

        $user = $this->createUserWithPermissions([
            'units' => ['view'],
        ]);

        $response = $this->actingAs($user)->get(route('units.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Units/Index')
            ->where('initialUnits.meta.total', 1)
            ->where('summary.total_units', 1)
            ->where('summary.active_units', 1)
            ->where('summary.inactive_units', 0)
            ->where('filteredMeta.filtered_total', 1)
            ->where('initialUnits.data.0.name', 'Unidad Matriz')
            ->where('initialUnits.data.0.company.name', 'Medicallife')
            ->where('companies.0.name', 'Medicallife')
        );
    }

    public function test_units_list_can_filter_by_company_search_and_status(): void
    {
        $companyNorth = Company::query()->create([
            'name' => 'Empresa Norte',
            'code' => 'NTE',
            'status' => 1,
        ]);

        $companySouth = Company::query()->create([
            'name' => 'Empresa Sur',
            'code' => 'SUR',
            'status' => 1,
        ]);

        Unit::query()->create([
            'company_id' => $companyNorth->id,
            'name' => 'Unidad Norte',
            'code' => 'UNT-NTE',
            'status' => 1,
            'timezone' => 'America/Mexico_City',
        ]);

        Unit::query()->create([
            'company_id' => $companySouth->id,
            'name' => 'Unidad Sur',
            'code' => 'UNT-SUR',
            'status' => 0,
            'timezone' => 'America/Mexico_City',
        ]);

        $user = $this->createUserWithPermissions([
            'units' => ['view'],
        ]);

        $response = $this->actingAs($user)->getJson(route('units.list', [
            'search' => 'Norte',
            'company_id' => $companyNorth->id,
            'status' => 1,
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.total_units', 2)
            ->assertJsonPath('summary.active_units', 1)
            ->assertJsonPath('summary.inactive_units', 1)
            ->assertJsonPath('filtered_meta.filtered_total', 1)
            ->assertJsonPath('data.0.name', 'Unidad Norte')
            ->assertJsonPath('data.0.company.name', 'Empresa Norte')
            ->assertJsonPath('data.0.status', 1);
    }

    public function test_authorized_user_can_create_show_update_and_toggle_unit_status(): void
    {
        $company = Company::query()->create([
            'name' => 'Empresa Demo',
            'code' => 'EMP',
            'status' => 1,
        ]);

        $user = $this->createUserWithPermissions([
            'units' => ['view', 'create', 'update', 'disable'],
        ]);

        $createResponse = $this->actingAs($user)->postJson(route('units.store'), [
            'company_id' => $company->id,
            'name' => 'Unidad Centro',
            'code' => 'CTR',
            'description' => 'Operacion principal',
            'city' => 'Monterrey',
            'state' => 'Nuevo Leon',
            'country' => 'Mexico',
            'address' => 'Av. Centro 123',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.name', 'Unidad Centro')
            ->assertJsonPath('data.company.name', 'Empresa Demo')
            ->assertJsonPath('data.status', 1);

        $unitId = $createResponse->json('data.id');

        Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $unitId,
            'clock_name' => 'Clock Centro',
            'status' => 1,
        ]);

        $this->actingAs($user)
            ->getJson(route('units.show', $unitId))
            ->assertOk()
            ->assertJsonPath('data.unit.name', 'Unidad Centro')
            ->assertJsonPath('data.clocks.0.clock_name', 'Clock Centro');

        $updateResponse = $this->actingAs($user)->putJson(route('units.update', $unitId), [
            'company_id' => $company->id,
            'name' => 'Unidad Centro Actualizada',
            'code' => 'CTR',
            'description' => 'Operacion principal actualizada',
            'city' => 'Guadalupe',
            'state' => 'Nuevo Leon',
            'country' => 'Mexico',
            'address' => 'Av. Centro 456',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.name', 'Unidad Centro Actualizada')
            ->assertJsonPath('data.city', 'Guadalupe');

        $toggleResponse = $this->actingAs($user)->putJson(route('units.toggle-status', $unitId));

        $toggleResponse->assertOk()
            ->assertJsonPath('data.status', 0)
            ->assertJsonPath('data.status_label', 'Inactiva');

        $this->assertDatabaseHas('locations', [
            'id' => $unitId,
            'name' => 'Unidad Centro Actualizada',
            'code' => 'CTR',
            'status' => 0,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'units.created',
            'auditable_type' => Unit::class,
            'auditable_id' => $unitId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'units.updated',
            'auditable_type' => Unit::class,
            'auditable_id' => $unitId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'units.status_changed',
            'auditable_type' => Unit::class,
            'auditable_id' => $unitId,
        ]);
    }

    public function test_requests_are_blocked_without_unit_permissions(): void
    {
        $company = Company::query()->create([
            'name' => 'Empresa Restringida',
            'code' => 'REST',
            'status' => 1,
        ]);

        $unit = Unit::query()->create([
            'company_id' => $company->id,
            'name' => 'Unidad Restringida',
            'code' => 'R01',
            'status' => 1,
            'timezone' => 'America/Mexico_City',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('units.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson(route('units.list'))
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson(route('units.show', $unit))
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('units.store'), [
                'company_id' => $company->id,
                'name' => 'Nueva unidad',
                'code' => 'NEW',
                'status' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson(route('units.update', $unit), [
                'company_id' => $company->id,
                'name' => 'Cambio no permitido',
                'code' => 'R01',
                'status' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson(route('units.toggle-status', $unit))
            ->assertForbidden();
    }

    /**
     * @param  array<string, array<int, string>>  $definitions
     */
    private function createUserWithPermissions(array $definitions): User
    {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => 'Role '.uniqid(),
            'description' => 'Role for unit module test',
            'is_system' => false,
        ]);

        $permissionIds = collect($definitions)
            ->flatMap(function (array $actions, string $module) {
                return Permission::query()
                    ->where('module', $module)
                    ->whereIn('action', $actions)
                    ->pluck('id');
            })
            ->unique()
            ->values()
            ->all();

        $role->permissions()->sync($permissionIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh('roles.permissions');
    }
}
