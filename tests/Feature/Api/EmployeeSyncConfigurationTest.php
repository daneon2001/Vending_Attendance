<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Fortia\FortiaEmployeeService;
use App\Services\FortiaMock\FortiaMockSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeSyncConfigurationTest extends TestCase
{
    private bool $createdUsersTable = false;
    private bool $createdRolesTable = false;
    private bool $createdRoleUserTable = false;
    private bool $createdPermissionsTable = false;
    private bool $createdPermissionRoleTable = false;
    private bool $createdEmployeesTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(HandleInertiaRequests::class);
        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
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

    public function test_admin_employees_endpoint_reports_sync_mode(): void
    {
        DB::table('employees')->insert([
            'fortia_employee_id' => 99001,
            'name' => 'Sync',
            'last_name' => 'Viewer',
            'full_name' => 'Sync Viewer',
            'status' => 'A',
            'has_fingerprint' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->createUserWithRoleAndPermission('Administrador', 'employees', 'view');
        Sanctum::actingAs($user);

        $this->mock(FortiaEmployeeService::class, function ($mock): void {
            $mock->shouldReceive('describeMode')
                ->once()
                ->andReturn([
                    'mode' => 'fortia',
                    'label' => 'Fortia',
                    'is_mock' => false,
                    'connection' => 'fortia',
                    'table' => 'fortia_employees',
                ]);
        });

        $response = $this->getJson('/api/admin/employees');

        $response->assertOk()
            ->assertJsonPath('sync.mode', 'fortia')
            ->assertJsonPath('sync.is_mock', false);
    }

    public function test_sync_endpoint_uses_mock_service_when_mode_is_mock(): void
    {
        $user = $this->createUserWithRoleAndPermission('Administrador', 'employees', 'sync');
        Sanctum::actingAs($user);

        $this->mock(FortiaEmployeeService::class, function ($mock): void {
            $mock->shouldReceive('describeMode')
                ->once()
                ->andReturn([
                    'mode' => 'mock',
                    'label' => 'Fortia Mock',
                    'is_mock' => true,
                    'connection' => 'fortia_mock',
                    'table' => 'fortia_employees',
                ]);
            $mock->shouldReceive('usingMockMode')->once()->andReturn(true);
            $mock->shouldReceive('syncEmployees')->never();
        });

        $this->mock(FortiaMockSyncService::class, function ($mock): void {
            $mock->shouldReceive('syncIncremental')
                ->once()
                ->andReturn([
                    'new' => 1,
                    'updated' => 2,
                    'unchanged' => 3,
                    'status_changed' => 0,
                    'changed' => [],
                ]);
        });

        $response = $this->postJson('/api/employees/sync-fortia');

        $response->assertOk()
            ->assertJsonPath('sync.mode', 'mock')
            ->assertJsonPath('sync.is_mock', true)
            ->assertJsonPath('created_count', 1);
    }

    public function test_sync_endpoint_uses_real_service_when_mode_is_fortia(): void
    {
        $user = $this->createUserWithRoleAndPermission('Administrador', 'employees', 'sync');
        Sanctum::actingAs($user);

        $this->mock(FortiaEmployeeService::class, function ($mock): void {
            $mock->shouldReceive('describeMode')
                ->once()
                ->andReturn([
                    'mode' => 'fortia',
                    'label' => 'Fortia',
                    'is_mock' => false,
                    'connection' => 'fortia',
                    'table' => 'fortia_employees',
                ]);
            $mock->shouldReceive('usingMockMode')->once()->andReturn(false);
            $mock->shouldReceive('syncEmployees')
                ->once()
                ->andReturn([
                    'new' => 2,
                    'updated' => 1,
                    'unchanged' => 4,
                    'status_changed' => 1,
                    'changed' => [
                        [
                            'fortia_employee_id' => 12345,
                            'old_status' => 'A',
                            'new_status' => 'B',
                        ],
                    ],
                ]);
        });

        $this->mock(FortiaMockSyncService::class, function ($mock): void {
            $mock->shouldReceive('syncIncremental')->never();
        });

        $response = $this->postJson('/api/employees/sync-fortia');

        $response->assertOk()
            ->assertJsonPath('sync.mode', 'fortia')
            ->assertJsonPath('sync.is_mock', false)
            ->assertJsonPath('status_changed_count', 1);
    }

    private function createUserWithRoleAndPermission(string $roleName, string $module, string $action): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Usuario '.str_replace(' ', '', $roleName),
            'email' => strtolower(str_replace(' ', '', $roleName)).'.sync@example.test',
            'password' => bcrypt('password'),
            'estatus' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = DB::table('roles')->insertGetId([
            'name' => $roleName,
            'description' => 'Rol de prueba',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->insertGetId([
            'module' => $module,
            'action' => $action,
            'name' => "{$module}.{$action}",
            'description' => 'Permiso de prueba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'role_id' => $roleId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permission_role')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($userId);
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

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->nullable()->unique();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('base_location_name')->nullable();
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('second_last_name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('status', 20)->default('A');
                $table->boolean('has_fingerprint')->default(false);
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }
    }

    private function cleanData(): void
    {
        foreach ([
            'employees',
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
