<?php

namespace Tests\Feature\Permissions;

use App\Actions\EnsureSuperAdmin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnsureSuperAdminCommandTest extends TestCase
{
    private bool $createdUsersTable = false;
    private bool $createdRolesTable = false;
    private bool $createdRoleUserTable = false;
    private bool $createdPermissionsTable = false;
    private bool $createdPermissionRoleTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        if ($this->createdPermissionRoleTable && Schema::hasTable('permission_role')) {
            Schema::drop('permission_role');
        }
        if ($this->createdPermissionsTable && Schema::hasTable('permissions')) {
            Schema::drop('permissions');
        }
        if ($this->createdRoleUserTable && Schema::hasTable('role_user')) {
            Schema::drop('role_user');
        }
        if ($this->createdRolesTable && Schema::hasTable('roles')) {
            Schema::drop('roles');
        }
        if ($this->createdUsersTable && Schema::hasTable('users')) {
            Schema::drop('users');
        }

        parent::tearDown();
    }

    public function test_it_backfills_permission_catalog_and_grants_all_modules_to_target_admin(): void
    {
        $user = User::query()->create([
            'name' => 'Route SuperAdmin',
            'email' => 'superadmin.route@example.test',
            'password' => bcrypt('password'),
            'estatus' => true,
        ]);

        $superAdminRole = Role::query()->create([
            'name' => 'SuperAdmin',
            'description' => 'Rol legado',
            'is_system' => true,
        ]);

        $templatesRead = Permission::query()->create([
            'module' => 'biometrics',
            'action' => 'templates.read',
            'name' => 'biometrics.templates.read',
            'description' => 'Permiso legado',
        ]);

        DB::table('permission_role')->insert([
            'role_id' => $superAdminRole->id,
            'permission_id' => $templatesRead->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'role_id' => $superAdminRole->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = EnsureSuperAdmin::run('superadmin.route@example.test');

        $this->assertSame('superadmin.route@example.test', $result['user']?->email);
        $this->assertSame('Administrador', $result['role']?->name);

        $user->load('roles.permissions');
        $permissionsMatrix = $user->permissionsMatrix();

        $this->assertArrayHasKey('settings', $permissionsMatrix);
        $this->assertContains('view', $permissionsMatrix['settings']);
        $this->assertArrayHasKey('users', $permissionsMatrix);
        $this->assertContains('view', $permissionsMatrix['users']);
        $this->assertArrayHasKey('audit', $permissionsMatrix);
        $this->assertContains('view', $permissionsMatrix['audit']);
        $this->assertArrayHasKey('asistencias', $permissionsMatrix);
        $this->assertContains('view', $permissionsMatrix['asistencias']);
        $this->assertArrayHasKey('biometrics', $permissionsMatrix);
        $this->assertContains('face.manage', $permissionsMatrix['biometrics']);
        $this->assertContains('templates.read', $permissionsMatrix['biometrics']);

        $expectedPermissionCount = collect(config('permissions.modules'))
            ->sum(fn (array $module) => count($module['actions'] ?? []));

        $this->assertSame($expectedPermissionCount, Permission::query()->count());
        $this->assertSame($expectedPermissionCount, $user->allPermissions()->count());
        $this->assertTrue($user->hasPermission('settings', 'view'));
        $this->assertTrue($user->hasPermission('users', 'view'));
        $this->assertTrue($user->hasPermission('audit', 'view'));
        $this->assertTrue($user->hasPermission('biometrics', 'face.manage'));
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->boolean('estatus')->default(true);
                $table->timestamps();
            });
            $this->createdUsersTable = true;
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('description')->nullable();
                $table->boolean('is_system')->default(false);
                $table->timestamps();
            });
            $this->createdRolesTable = true;
        }

        if (! Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
            });
            $this->createdRoleUserTable = true;
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table): void {
                $table->id();
                $table->string('module');
                $table->string('action');
                $table->string('name');
                $table->string('description')->nullable();
                $table->timestamps();
            });
            $this->createdPermissionsTable = true;
        }

        if (! Schema::hasTable('permission_role')) {
            Schema::create('permission_role', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->timestamps();
            });
            $this->createdPermissionRoleTable = true;
        }
    }

    private function cleanData(): void
    {
        foreach ([
            'permission_role',
            'permissions',
            'role_user',
            'roles',
            'users',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }
}
