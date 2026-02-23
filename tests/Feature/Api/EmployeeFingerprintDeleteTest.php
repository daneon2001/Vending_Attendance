<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeFingerprintDeleteTest extends TestCase
{
    private bool $createdUsersTable = false;
    private bool $createdRolesTable = false;
    private bool $createdRoleUserTable = false;
    private bool $createdPermissionsTable = false;
    private bool $createdPermissionRoleTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdEmployeeTemplateDeletionsTable = false;
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
        if ($this->createdEmployeeTemplateDeletionsTable && Schema::hasTable('employee_template_deletions')) {
            Schema::drop('employee_template_deletions');
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

    public function test_admin_can_delete_fingerprints_and_returns_deleted_count(): void
    {
        $employeeId = $this->seedEmployeeWithFingerprints(2);
        $user = $this->createUserWithRoleAndPermission('Administrador', 'biometrics', 'fingerprints.delete');
        Sanctum::actingAs($user);

        $response = $this
            ->withHeaders([
                'X-Correlation-Id' => 'corr-delete-ok-001',
            ])
            ->deleteJson("/api/admin/employees/{$employeeId}/fingerprints");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_count', 2);

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $cacheControl);

        $this->assertSame(
            0,
            DB::table('employee_fingerprints')->where('employee_id', $employeeId)->count()
        );
        $this->assertDatabaseHas('employees', [
            'id' => $employeeId,
            'has_fingerprint' => 0,
        ]);
        $this->assertSame(
            2,
            DB::table('employee_template_deletions')->where('employee_id', $employeeId)->count()
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'biometric.fingerprint.deleted',
            'entity' => 'employee_fingerprints',
            'reason' => 'fingerprints_deleted',
            'correlation_id' => 'corr-delete-ok-001',
        ]);

        $newValues = json_decode((string) DB::table('audit_logs')
            ->where('action', 'biometric.fingerprint.deleted')
            ->latest('id')
            ->value('new_values'), true);

        $this->assertIsArray($newValues);
        $this->assertSame($employeeId, $newValues['employee_id'] ?? null);
        $this->assertSame(2, $newValues['deleted_count'] ?? null);
        $this->assertSame(200, $newValues['response_status'] ?? null);
        $this->assertNotEmpty($newValues['request_id'] ?? null);
    }

    public function test_admin_delete_without_existing_fingerprints_returns_zero(): void
    {
        $employeeId = $this->seedEmployeeWithoutFingerprints();
        $user = $this->createUserWithRoleAndPermission('Admin', 'biometrics', 'fingerprints.delete');
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/admin/employees/{$employeeId}/fingerprints");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_count', 0);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'biometric.fingerprint.deleted',
            'entity' => 'employee_fingerprints',
            'reason' => 'fingerprints_deleted',
        ]);
    }

    public function test_user_without_delete_permission_gets_403_and_audit_is_logged(): void
    {
        $employeeId = $this->seedEmployeeWithFingerprints(1);
        $user = $this->createUserWithRoleAndPermission('Administrador', 'employees', 'view');
        Sanctum::actingAs($user);

        $response = $this
            ->withHeaders([
                'X-Correlation-Id' => 'corr-delete-denied-001',
            ])
            ->deleteJson("/api/admin/employees/{$employeeId}/fingerprints");

        $response->assertForbidden();

        $this->assertSame(
            1,
            DB::table('employee_fingerprints')->where('employee_id', $employeeId)->count()
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'biometric.fingerprint.deleted',
            'entity' => 'employee_fingerprints',
            'reason' => 'access_denied',
            'correlation_id' => 'corr-delete-denied-001',
        ]);
    }

    public function test_delete_endpoint_is_throttled(): void
    {
        $employeeId = $this->seedEmployeeWithFingerprints(1);
        $user = $this->createUserWithRoleAndPermission('Administrador', 'biometrics', 'fingerprints.delete');
        Sanctum::actingAs($user);

        RateLimiter::clear('biometrics-delete:'.$user->id);

        for ($i = 0; $i < 30; $i++) {
            $response = $this->deleteJson("/api/admin/employees/{$employeeId}/fingerprints");
            $response->assertOk();
        }

        $response = $this->deleteJson("/api/admin/employees/{$employeeId}/fingerprints");
        $response->assertStatus(429);
    }

    private function seedEmployeeWithFingerprints(int $count): int
    {
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => random_int(60000, 69999),
            'name' => 'Delete',
            'last_name' => 'Target',
            'full_name' => 'Delete Target',
            'status' => 'A',
            'has_fingerprint' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 1; $i <= $count; $i++) {
            DB::table('employee_fingerprints')->insert([
                'employee_id' => $employeeId,
                'vendor_template_id' => "TPL-DEL-{$employeeId}-{$i}",
                'template_b64' => str_repeat('ABC', 50),
                'template_format' => 'zkteco-v1',
                'enrolment_type' => 'FINGERPRINT',
                'status' => 'enrolled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $employeeId;
    }

    private function seedEmployeeWithoutFingerprints(): int
    {
        return DB::table('employees')->insertGetId([
            'fortia_employee_id' => random_int(70000, 79999),
            'name' => 'No',
            'last_name' => 'Fingerprints',
            'full_name' => 'No Fingerprints',
            'status' => 'A',
            'has_fingerprint' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUserWithRoleAndPermission(string $roleName, string $module, string $action): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Usuario '.str_replace(' ', '', $roleName),
            'email' => strtolower(str_replace(' ', '', $roleName)).'.delete@example.test',
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
                $table->dateTime('deleted_at')->nullable();
                $table->timestamps();
            });
            $this->createdEmployeeFingerprintsTable = true;
        }

        if (! Schema::hasTable('employee_template_deletions')) {
            Schema::create('employee_template_deletions', function (Blueprint $table): void {
                $table->id();
                $table->string('vendor', 40);
                $table->string('vendor_template_id', 191);
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->dateTime('deleted_at');
                $table->timestamps();
            });
            $this->createdEmployeeTemplateDeletionsTable = true;
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
            'employee_template_deletions',
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
