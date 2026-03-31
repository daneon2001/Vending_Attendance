<?php

namespace Tests\Feature\Permissions;

use App\Actions\SyncPermissionCatalog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoleSystemUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SyncPermissionCatalog::run();
    }

    public function test_system_role_can_update_description_and_companies_permissions_when_name_matches(): void
    {
        $role = Role::query()->create([
            'name' => 'Administrador',
            'description' => 'Acceso total al sistema',
            'is_system' => true,
        ]);

        $user = $this->createUserWithSettingsPermissions(['update']);

        $response = $this->actingAs($user)->putJson(route('settings.roles.update', $role), [
            'name' => 'Administrador',
            'description' => 'Acceso total ajustado',
            'permissions' => [
                'companies' => ['view', 'create', 'update', 'disable', 'manage'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Administrador')
            ->assertJsonPath('data.description', 'Acceso total ajustado');

        $role->refresh()->load('permissions');

        $this->assertSame('Administrador', $role->name);
        $this->assertSame('Acceso total ajustado', $role->description);
        $this->assertSame(
            ['create', 'disable', 'manage', 'update', 'view'],
            $role->permissions
                ->where('module', 'companies')
                ->pluck('action')
                ->sort()
                ->values()
                ->all()
        );
    }

    public function test_system_role_rejects_a_real_rename_attempt(): void
    {
        $role = Role::query()->create([
            'name' => 'Administrador',
            'description' => 'Acceso total al sistema',
            'is_system' => true,
        ]);

        $user = $this->createUserWithSettingsPermissions(['update']);

        $response = $this->actingAs($user)->putJson(route('settings.roles.update', $role), [
            'name' => 'Administrador Global',
            'description' => 'Descripcion nueva',
            'permissions' => [
                'companies' => ['view', 'manage'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $role->refresh()->load('permissions');

        $this->assertSame('Administrador', $role->name);
        $this->assertSame('Acceso total al sistema', $role->description);
        $this->assertSame(0, $role->permissions->where('module', 'companies')->count());
    }

    public function test_roles_page_exposes_companies_module_in_permissions_ui(): void
    {
        $user = $this->createUserWithSettingsPermissions(['view']);

        $response = $this->actingAs($user)->get(route('settings.roles.page'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Roles/Index')
            ->where('modules.companies.label', 'Catalogo de empresas')
            ->where('modules.companies.actions.0', 'view')
            ->where('modules.companies.actions.4', 'manage')
        );
    }

    public function test_system_role_can_update_description_and_units_permissions_when_name_matches(): void
    {
        $role = Role::query()->create([
            'name' => 'Administrador',
            'description' => 'Acceso total al sistema',
            'is_system' => true,
        ]);

        $user = $this->createUserWithSettingsPermissions(['update']);

        $response = $this->actingAs($user)->putJson(route('settings.roles.update', $role), [
            'name' => 'Administrador',
            'description' => 'Acceso total con unidades',
            'permissions' => [
                'units' => ['view', 'create', 'update', 'disable', 'manage'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Administrador')
            ->assertJsonPath('data.description', 'Acceso total con unidades')
            ->assertJsonPath('data.permissions.units', ['view', 'create', 'update', 'disable', 'manage']);

        $role->refresh()->load('permissions');

        $this->assertSame(
            ['create', 'disable', 'manage', 'update', 'view'],
            $role->permissions
                ->where('module', 'units')
                ->pluck('action')
                ->sort()
                ->values()
                ->all()
        );
    }

    public function test_roles_page_exposes_units_module_in_permissions_ui(): void
    {
        $user = $this->createUserWithSettingsPermissions(['view']);

        $response = $this->actingAs($user)->get(route('settings.roles.page'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Roles/Index')
            ->where('modules.units.label', 'Catalogo de unidades')
            ->where('modules.units.actions.0', 'view')
            ->where('modules.units.actions.4', 'manage')
        );
    }

    public function test_roles_page_rebuilds_missing_companies_permissions_in_database(): void
    {
        Permission::query()->where('module', 'companies')->delete();
        $this->assertSame(0, Permission::query()->where('module', 'companies')->count());

        $user = $this->createUserWithSettingsPermissions(['view']);

        $this->actingAs($user)->get(route('settings.roles.page'))->assertOk();

        $this->assertSame(5, Permission::query()->where('module', 'companies')->count());
        $this->assertDatabaseHas('permissions', [
            'module' => 'companies',
            'action' => 'view',
        ]);
        $this->assertDatabaseHas('permissions', [
            'module' => 'companies',
            'action' => 'manage',
        ]);
    }

    public function test_roles_page_rebuilds_missing_units_permissions_in_database(): void
    {
        Permission::query()->where('module', 'units')->delete();
        $this->assertSame(0, Permission::query()->where('module', 'units')->count());

        $user = $this->createUserWithSettingsPermissions(['view']);

        $this->actingAs($user)->get(route('settings.roles.page'))->assertOk();

        $this->assertSame(5, Permission::query()->where('module', 'units')->count());
        $this->assertDatabaseHas('permissions', [
            'module' => 'units',
            'action' => 'view',
        ]);
        $this->assertDatabaseHas('permissions', [
            'module' => 'units',
            'action' => 'manage',
        ]);
    }

    public function test_role_update_recreates_missing_companies_permissions_and_persists_permission_ids(): void
    {
        $role = Role::query()->create([
            'name' => 'Coordinador',
            'description' => 'Rol operativo',
            'is_system' => false,
        ]);

        Permission::query()->where('module', 'companies')->delete();
        $this->assertSame(0, Permission::query()->where('module', 'companies')->count());

        $user = $this->createUserWithSettingsPermissions(['update', 'view']);

        $response = $this->actingAs($user)->putJson(route('settings.roles.update', $role), [
            'name' => 'Coordinador',
            'description' => 'Rol operativo actualizado',
            'permissions' => [
                'companies' => ['view', 'create', 'update', 'disable', 'manage'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.description', 'Rol operativo actualizado');

        $role->refresh()->load('permissions');

        $companyPermissions = Permission::query()
            ->where('module', 'companies')
            ->orderBy('action')
            ->get();

        $this->assertCount(5, $companyPermissions);
        $this->assertSame(
            ['create', 'disable', 'manage', 'update', 'view'],
            $companyPermissions->pluck('action')->all()
        );

        foreach ($companyPermissions as $permission) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        $this->assertSame(
            ['create', 'disable', 'manage', 'update', 'view'],
            $role->permissions
                ->where('module', 'companies')
                ->pluck('action')
                ->sort()
                ->values()
                ->all()
        );

        $this->actingAs($user)
            ->getJson(route('settings.roles.index'))
            ->assertOk()
            ->assertJsonFragment([
                'companies' => ['view', 'create', 'update', 'disable', 'manage'],
            ]);
    }

    public function test_role_update_recreates_missing_units_permissions_and_persists_permission_ids(): void
    {
        $role = Role::query()->create([
            'name' => 'Jefatura',
            'description' => 'Rol operativo de unidades',
            'is_system' => false,
        ]);

        Permission::query()->where('module', 'units')->delete();
        $this->assertSame(0, Permission::query()->where('module', 'units')->count());

        $user = $this->createUserWithSettingsPermissions(['update', 'view']);

        $response = $this->actingAs($user)->putJson(route('settings.roles.update', $role), [
            'name' => 'Jefatura',
            'description' => 'Rol operativo de unidades actualizado',
            'permissions' => [
                'units' => ['view', 'create', 'update', 'disable', 'manage'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.description', 'Rol operativo de unidades actualizado')
            ->assertJsonPath('data.permissions.units', ['view', 'create', 'update', 'disable', 'manage']);

        $role->refresh()->load('permissions');

        $unitPermissions = Permission::query()
            ->where('module', 'units')
            ->orderBy('action')
            ->get();

        $this->assertCount(5, $unitPermissions);
        $this->assertSame(
            ['create', 'disable', 'manage', 'update', 'view'],
            $unitPermissions->pluck('action')->all()
        );

        foreach ($unitPermissions as $permission) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        $this->assertSame(
            ['create', 'disable', 'manage', 'update', 'view'],
            $role->permissions
                ->where('module', 'units')
                ->pluck('action')
                ->sort()
                ->values()
                ->all()
        );

        $this->actingAs($user)
            ->getJson(route('settings.roles.index'))
            ->assertOk()
            ->assertJsonFragment([
                'units' => ['view', 'create', 'update', 'disable', 'manage'],
            ]);
    }

    public function test_roles_index_maps_legacy_locations_permissions_to_units_module(): void
    {
        $legacyView = Permission::query()->create([
            'module' => 'locations',
            'action' => 'view',
            'name' => 'Locations - View',
            'description' => 'Permite ver sucursales legacy',
        ]);

        $legacyUpdate = Permission::query()->create([
            'module' => 'locations',
            'action' => 'update',
            'name' => 'Locations - Update',
            'description' => 'Permite actualizar sucursales legacy',
        ]);

        $role = Role::query()->create([
            'name' => 'Operador legado',
            'description' => 'Rol con permisos legacy',
            'is_system' => false,
        ]);

        $role->permissions()->sync([$legacyView->id, $legacyUpdate->id]);

        $user = $this->createUserWithSettingsPermissions(['view']);

        $this->actingAs($user)
            ->getJson(route('settings.roles.index'))
            ->assertOk()
            ->assertJsonFragment([
                'units' => ['view', 'update'],
            ]);
    }

    /**
     * @param  array<int, string>  $actions
     */
    private function createUserWithSettingsPermissions(array $actions): User
    {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => 'Settings '.uniqid(),
            'description' => 'Role for settings tests',
            'is_system' => false,
        ]);

        $permissionIds = Permission::query()
            ->where('module', 'settings')
            ->whereIn('action', $actions)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($permissionIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh('roles.permissions');
    }
}
