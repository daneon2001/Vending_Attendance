<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeBiometricAccessTest extends TestCase
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
        if ($this->createdRoleUserTable && Schema::hasTable('role_user')) {
            Schema::drop('role_user');
        }
        if ($this->createdPermissionRoleTable && Schema::hasTable('permission_role')) {
            Schema::drop('permission_role');
        }
        if ($this->createdPermissionsTable && Schema::hasTable('permissions')) {
            Schema::drop('permissions');
        }
        if ($this->createdRolesTable && Schema::hasTable('roles')) {
            Schema::drop('roles');
        }
        if ($this->createdUsersTable && Schema::hasTable('users')) {
            Schema::drop('users');
        }

        parent::tearDown();
    }

    public function test_employees_index_never_exposes_template_b64_even_with_include_fingerprints(): void
    {
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 55001,
            'name' => 'Biometria',
            'last_name' => 'Empleado',
            'full_name' => 'Biometria Empleado',
            'status' => 'A',
            'has_fingerprint' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-SEC-001',
            'template_b64' => str_repeat('AAABBBCCC', 300),
            'template_format' => 'zkteco-v1',
            'enrolment_type' => 'FINGERPRINT',
            'status' => 'enrolled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/employees?include=fingerprints&per_page=15');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.0.fingerprints'));
        $fingerprint = $response->json('data.0.fingerprints.0');

        $this->assertArrayHasKey('id', $fingerprint);
        $this->assertArrayHasKey('type', $fingerprint);
        $this->assertArrayHasKey('quality', $fingerprint);
        $this->assertArrayHasKey('created_at', $fingerprint);
        $this->assertArrayNotHasKey('template_b64', $fingerprint);
        $this->assertSame('FINGERPRINT', $fingerprint['type']);

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('template_b64', $payload);
    }

    public function test_employees_index_does_not_include_fingerprints_by_default(): void
    {
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 56001,
            'name' => 'Listado',
            'last_name' => 'Ligero',
            'full_name' => 'Listado Ligero',
            'status' => 'A',
            'has_fingerprint' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-SEC-002',
            'template_b64' => str_repeat('ZZZYYYXXX', 300),
            'template_format' => 'zkteco-v1',
            'enrolment_type' => 'FINGERPRINT',
            'status' => 'enrolled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/employees?per_page=15');

        $response->assertOk();

        $employee = (array) $response->json('data.0');
        $this->assertArrayNotHasKey('fingerprints', $employee);
    }

    public function test_templates_endpoint_requires_superadmin_role(): void
    {
        $employeeId = $this->seedEmployeeWithTemplate();
        $user = $this->createUserWithRoleAndPermission('RH', 'biometrics', 'templates.read');
        Sanctum::actingAs($user);

        $response = $this
            ->withHeaders([
                'X-Correlation-Id' => 'corr-denied-001',
            ])
            ->getJson("/api/superadmin/employees/{$employeeId}/fingerprints/templates");

        $response->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'biometric.template.accessed',
            'entity' => 'employee_fingerprints',
            'reason' => 'access_denied',
            'correlation_id' => 'corr-denied-001',
        ]);
    }

    public function test_templates_endpoint_returns_template_for_superadmin_and_logs_access(): void
    {
        $employeeId = $this->seedEmployeeWithTemplate();
        $user = $this->createUserWithRoleAndPermission('SuperAdmin', 'biometrics', 'templates.read');
        Sanctum::actingAs($user);

        $response = $this
            ->withHeaders([
                'X-Correlation-Id' => 'corr-ok-001',
            ])
            ->getJson("/api/superadmin/employees/{$employeeId}/fingerprints/templates");

        $response->assertOk()
            ->assertJsonPath('ok', true);

        $template = $response->json('data.0.template_b64');
        $this->assertNotNull($template);
        $this->assertStringContainsString('AAABBBCCC', $template);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'biometric.template.accessed',
            'entity' => 'employee_fingerprints',
            'reason' => 'templates_read',
            'correlation_id' => 'corr-ok-001',
        ]);

        $auditPayload = DB::table('audit_logs')
            ->where('action', 'biometric.template.accessed')
            ->latest('id')
            ->value('new_values');

        $newValues = json_decode((string) $auditPayload, true);
        $this->assertIsArray($newValues);
        $this->assertSame($employeeId, $newValues['employee_id'] ?? null);
        $this->assertIsArray($newValues['fingerprint_ids'] ?? null);
        $this->assertNotEmpty($newValues['fingerprint_ids'] ?? []);
        $this->assertNotEmpty($newValues['ip'] ?? null);
        $this->assertNotEmpty($newValues['user_agent'] ?? null);
        $this->assertSame("api/superadmin/employees/{$employeeId}/fingerprints/templates", $newValues['route'] ?? null);
        $this->assertNotEmpty($newValues['request_id'] ?? null);
    }

    private function seedEmployeeWithTemplate(): int
    {
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => random_int(70000, 79999),
            'name' => 'Super',
            'last_name' => 'Empleado',
            'full_name' => 'Super Empleado',
            'status' => 'A',
            'has_fingerprint' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-SUPER-001',
            'template_b64' => str_repeat('AAABBBCCC', 200),
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
            'email' => strtolower(str_replace(' ', '', $roleName)).'@example.test',
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
        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->delete();
        }

        if (Schema::hasTable('employee_fingerprints')) {
            DB::table('employee_fingerprints')->delete();
        }

        if (Schema::hasTable('employees')) {
            DB::table('employees')->delete();
        }

        if (Schema::hasTable('role_user')) {
            DB::table('role_user')->delete();
        }

        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->delete();
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')->delete();
        }

        if (Schema::hasTable('users')) {
            DB::table('users')->delete();
        }
    }
}
