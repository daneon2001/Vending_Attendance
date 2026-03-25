<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeBiometricRouteSecurityTest extends TestCase
{
    private bool $createdUsersTable = false;
    private bool $createdRolesTable = false;
    private bool $createdRoleUserTable = false;
    private bool $createdPermissionsTable = false;
    private bool $createdPermissionRoleTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdAuditLogsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(HandleInertiaRequests::class);
        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        if ($this->createdAuditLogsTable && Schema::hasTable('audit_logs')) {
            Schema::drop('audit_logs');
        }
        if ($this->createdEmployeeFingerprintsTable && Schema::hasTable('employee_fingerprints')) {
            Schema::drop('employee_fingerprints');
        }
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

    public function test_biometric_routes_are_registered_in_api_stack_not_web_stack(): void
    {
        $adminRoute = $this->findRoute('GET', 'api/admin/employees/{employee}/fingerprints');
        $this->assertNotNull($adminRoute);
        $adminMiddleware = $adminRoute->middleware();
        $this->assertContains('api', $adminMiddleware);
        $this->assertNotContains('web', $adminMiddleware);
        $this->assertContains('auth:sanctum', $adminMiddleware);
        $this->assertContains('audit.biometric', $adminMiddleware);
        $this->assertContains('perm.strict:biometrics,fingerprints.read', $adminMiddleware);
        $this->assertContains('throttle:biometrics-fingerprints', $adminMiddleware);

        $deleteRoute = $this->findRoute('DELETE', 'api/admin/employees/{employee}/fingerprints');
        $this->assertNotNull($deleteRoute);
        $deleteMiddleware = $deleteRoute->middleware();
        $this->assertContains('api', $deleteMiddleware);
        $this->assertNotContains('web', $deleteMiddleware);
        $this->assertContains('auth:web,sanctum', $deleteMiddleware);
        $this->assertContains('audit.biometric', $deleteMiddleware);
        $this->assertContains('role:administrador,admin,superadmin', $deleteMiddleware);
        $this->assertContains('perm.strict:biometrics,fingerprints.delete', $deleteMiddleware);
        $this->assertContains('throttle:biometrics-delete', $deleteMiddleware);

        $templateRoute = $this->findRoute('GET', 'api/superadmin/employees/{employee}/fingerprints/templates');
        $this->assertNotNull($templateRoute);
        $templateMiddleware = $templateRoute->middleware();
        $this->assertContains('api', $templateMiddleware);
        $this->assertNotContains('web', $templateMiddleware);
        $this->assertContains('auth:web,sanctum', $templateMiddleware);
        $this->assertContains('audit.biometric', $templateMiddleware);
        $this->assertContains('perm.strict:biometrics,templates.read', $templateMiddleware);
        $this->assertContains('throttle:biometrics-templates', $templateMiddleware);

        $faceRoute = $this->findRoute('PATCH', 'api/admin/employees/{employee}/face-profile');
        $this->assertNotNull($faceRoute);
        $faceMiddleware = $faceRoute->middleware();
        $this->assertContains('api', $faceMiddleware);
        $this->assertNotContains('web', $faceMiddleware);
        $this->assertContains('auth:web,sanctum', $faceMiddleware);
        $this->assertContains('audit.biometric', $faceMiddleware);
        $this->assertContains('role:administrador,admin,superadmin', $faceMiddleware);
        $this->assertContains('perm.strict:biometrics,face.manage', $faceMiddleware);
        $this->assertContains('throttle:biometrics-face', $faceMiddleware);

        $this->assertNull($this->findRoute('GET', 'api/employees/{employee}'));
        $this->assertNull($this->findRoute('POST', 'api/employees/{employee}/fingerprints'));
    }

    public function test_templates_endpoint_has_basic_throttle_limit(): void
    {
        $employeeId = $this->seedEmployeeWithTemplate();
        $user = $this->createUserWithRoleAndPermission('SuperAdmin', 'biometrics', 'templates.read');
        Sanctum::actingAs($user);

        RateLimiter::clear('biometrics-templates:'.$user->id);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->getJson("/api/superadmin/employees/{$employeeId}/fingerprints/templates");
            $response->assertOk();
        }

        $response = $this->getJson("/api/superadmin/employees/{$employeeId}/fingerprints/templates");
        $response->assertStatus(429);
    }

    private function findRoute(string $method, string $uri)
    {
        $method = strtoupper($method);

        return collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));
    }

    private function seedEmployeeWithTemplate(): int
    {
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => random_int(81000, 89999),
            'name' => 'Throttle',
            'last_name' => 'User',
            'full_name' => 'Throttle User',
            'status' => 'A',
            'has_fingerprint' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-THROTTLE-001',
            'template_b64' => str_repeat('TTT', 100),
            'template_format' => 'zkteco-v1',
            'enrolment_type' => 'FINGERPRINT',
            'status' => 'enrolled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employeeId;
    }

    private function createUserWithRoleAndPermission(string $roleName, string $module, string $action): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Usuario '.str_replace(' ', '', $roleName),
            'email' => strtolower(str_replace(' ', '', $roleName)).'.route@example.test',
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

        if (! Schema::hasTable('employee_fingerprints')) {
            Schema::create('employee_fingerprints', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('vendor_template_id')->nullable();
                $table->longText('template_b64')->nullable();
                $table->string('template_format', 40)->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('status', 30)->default('enrolled');
                $table->string('device_serial')->nullable();
                $table->dateTime('performed_at')->nullable();
                $table->timestamps();
            });
            $this->createdEmployeeFingerprintsTable = true;
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_type', 40)->nullable();
                $table->string('actor_identifier', 191)->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_email')->nullable();
                $table->string('event');
                $table->string('action', 60)->nullable();
                $table->string('entity', 120)->nullable();
                $table->string('entity_id', 120)->nullable();
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->text('description')->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('request_id', 64)->nullable();
                $table->string('correlation_id', 64)->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->dateTime('occurred_at_utc')->nullable();
                $table->dateTime('occurred_at_local')->nullable();
                $table->string('timezone', 64)->nullable();
                $table->timestamps();
            });
            $this->createdAuditLogsTable = true;
        }
    }

    private function cleanData(): void
    {
        foreach ([
            'audit_logs',
            'employee_fingerprints',
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
