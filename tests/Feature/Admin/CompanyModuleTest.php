<?php

namespace Tests\Feature\Admin;

use App\Actions\SyncPermissionCatalog;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SyncPermissionCatalog::run();
    }

    public function test_authorized_user_can_view_companies_page(): void
    {
        $company = Company::query()->create([
            'name' => 'Medicallife',
            'code' => 'MLF',
            'status' => 1,
        ]);

        Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Matriz',
            'code' => 'MTR',
            'status' => 1,
        ]);

        $user = $this->createUserWithPermissions([
            'companies' => ['view'],
        ]);

        $response = $this->actingAs($user)->get(route('companies.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Companies/Index')
            ->where('initialCompanies.meta.total', 1)
            ->where('initialCompanies.data.0.name', 'Medicallife')
            ->where('initialCompanies.data.0.units_count', 1)
        );
    }

    public function test_companies_list_can_filter_by_search_and_status(): void
    {
        Company::query()->create([
            'name' => 'Empresa Norte',
            'code' => 'NORTE',
            'status' => 1,
        ]);

        Company::query()->create([
            'name' => 'Empresa Sur',
            'code' => 'SUR',
            'status' => 0,
        ]);

        $user = $this->createUserWithPermissions([
            'companies' => ['view'],
        ]);

        $response = $this->actingAs($user)->getJson(route('companies.list', [
            'search' => 'Norte',
            'status' => 1,
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Empresa Norte')
            ->assertJsonPath('data.0.status', 1);
    }

    public function test_authorized_user_can_create_update_and_toggle_company_status(): void
    {
        $user = $this->createUserWithPermissions([
            'companies' => ['view', 'create', 'update', 'disable'],
        ]);

        $createResponse = $this->actingAs($user)->postJson(route('companies.store'), [
            'name' => 'Empresa Demo',
            'code' => 'demo',
            'status' => 1,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.name', 'Empresa Demo')
            ->assertJsonPath('data.code', 'DEMO')
            ->assertJsonPath('data.status', 1);

        $companyId = $createResponse->json('data.id');

        $location = Location::query()->create([
            'company_id' => $companyId,
            'name' => 'Sucursal Centro',
            'code' => 'CTR',
            'status' => 1,
        ]);

        Clock::query()->create([
            'company_id' => $companyId,
            'location_id' => $location->id,
            'clock_name' => 'Clock Centro',
            'status' => 1,
        ]);

        $updateResponse = $this->actingAs($user)->putJson(route('companies.update', $companyId), [
            'name' => 'Empresa Demo Actualizada',
            'code' => 'DEM2',
            'status' => 1,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.name', 'Empresa Demo Actualizada')
            ->assertJsonPath('data.code', 'DEM2')
            ->assertJsonPath('data.units_count', 1)
            ->assertJsonPath('data.clocks_count', 1);

        $toggleResponse = $this->actingAs($user)->putJson(route('companies.toggle-status', $companyId));

        $toggleResponse->assertOk()
            ->assertJsonPath('data.status', 0)
            ->assertJsonPath('data.status_label', 'Inactiva');

        $this->assertDatabaseHas('companies', [
            'id' => $companyId,
            'name' => 'Empresa Demo Actualizada',
            'code' => 'DEM2',
            'status' => 0,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'companies.created',
            'auditable_type' => Company::class,
            'auditable_id' => $companyId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'companies.updated',
            'auditable_type' => Company::class,
            'auditable_id' => $companyId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'companies.status_changed',
            'auditable_type' => Company::class,
            'auditable_id' => $companyId,
        ]);
    }

    public function test_requests_are_blocked_without_company_permissions(): void
    {
        $company = Company::query()->create([
            'name' => 'Empresa Restringida',
            'code' => 'REST',
            'status' => 1,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('companies.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson(route('companies.list'))
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('companies.store'), [
                'name' => 'Nueva',
                'code' => 'NEW',
                'status' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson(route('companies.update', $company), [
                'name' => 'Cambio no permitido',
                'code' => 'REST',
                'status' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson(route('companies.toggle-status', $company))
            ->assertForbidden();
    }

    public function test_validation_blocks_duplicate_name_and_code_on_create(): void
    {
        Company::query()->create([
            'name' => 'Empresa Unica',
            'code' => 'UNICA',
            'status' => 1,
        ]);

        $user = $this->createUserWithPermissions([
            'companies' => ['view', 'create'],
        ]);

        $response = $this->actingAs($user)->postJson(route('companies.store'), [
            'name' => 'empresa unica',
            'code' => 'unica',
            'status' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code']);
    }

    /**
     * @param  array<string, array<int, string>>  $definitions
     */
    private function createUserWithPermissions(array $definitions): User
    {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => 'Role '.uniqid(),
            'description' => 'Role for company module test',
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
