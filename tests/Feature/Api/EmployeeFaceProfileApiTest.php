<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeFaceProfileApiTest extends TestCase
{
    private bool $createdUsersTable = false;
    private bool $createdRolesTable = false;
    private bool $createdRoleUserTable = false;
    private bool $createdPermissionsTable = false;
    private bool $createdPermissionRoleTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdTemplateDeletionsTable = false;
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
        if ($this->createdTemplateDeletionsTable && Schema::hasTable('employee_template_deletions')) {
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

    public function test_admin_can_update_face_profile(): void
    {
        $employeeId = $this->seedEmployeeWithFaceTemplate();
        $user = $this->createUserWithRoleAndPermission('Administrador', 'biometrics', 'face.manage');
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/admin/employees/{$employeeId}/face-profile", [
            'face_enabled' => true,
            'face_status' => 'ready',
            'face_samples_count' => 5,
            'face_template_version' => 'FACE_V3',
            'face_quality_score' => 96,
            'face_meta' => [
                'capture_source' => 'enroller-app',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('employee.face_status', 'ready')
            ->assertJsonPath('employee.face_sync_ready', true);

        $this->assertDatabaseHas('employees', [
            'id' => $employeeId,
            'face_enabled' => 1,
            'face_status' => 'ready',
            'face_samples_count' => 5,
            'face_template_version' => 'FACE_V3',
            'face_quality_score' => 96,
        ]);
    }

    public function test_admin_can_delete_face_templates_and_generates_face_tombstones(): void
    {
        $employeeId = $this->seedEmployeeWithFaceTemplate();
        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'vendor_template_id' => 'FACE-DELETE-002',
            'template_b64' => str_repeat('BBB', 80),
            'template_format' => 'FACE_EMBEDDING_V1',
            'enrolment_type' => 'FACE',
            'status' => 'enrolled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->createUserWithRoleAndPermission('Administrador', 'biometrics', 'face.manage');
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/admin/employees/{$employeeId}/face-profile");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_count', 2)
            ->assertJsonPath('employee.has_face_enrollment', false)
            ->assertJsonPath('employee.face_status', 'none');

        $this->assertSame(
            0,
            DB::table('employee_fingerprints')
                ->where('employee_id', $employeeId)
                ->where('enrolment_type', 'FACE')
                ->count()
        );
        $this->assertSame(
            2,
            DB::table('employee_template_deletions')
                ->where('employee_id', $employeeId)
                ->where('biometric_type', 'FACE')
                ->count()
        );
    }

    private function seedEmployeeWithFaceTemplate(): int
    {
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => random_int(81000, 89999),
            'name' => 'Face',
            'last_name' => 'Target',
            'full_name' => 'Face Target',
            'status' => 'A',
            'has_fingerprint' => false,
            'has_face_enrollment' => true,
            'face_status' => 'enrolled',
            'face_samples_count' => 2,
            'face_template_version' => 'FACE_V1',
            'face_enabled' => true,
            'face_quality_score' => 89,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'vendor_template_id' => 'FACE-DELETE-001',
            'template_b64' => str_repeat('AAA', 80),
            'template_format' => 'FACE_EMBEDDING_V1',
            'enrolment_type' => 'FACE',
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
            'email' => strtolower(str_replace(' ', '', $roleName)).'.face@example.test',
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

        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'has_face_enrollment')) {
                $table->boolean('has_face_enrollment')->default(false);
            }
            if (! Schema::hasColumn('employees', 'face_status')) {
                $table->string('face_status', 30)->default('none');
            }
            if (! Schema::hasColumn('employees', 'face_samples_count')) {
                $table->unsignedInteger('face_samples_count')->default(0);
            }
            if (! Schema::hasColumn('employees', 'face_template_version')) {
                $table->string('face_template_version', 80)->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_updated_at')) {
                $table->dateTime('face_updated_at')->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_enabled')) {
                $table->boolean('face_enabled')->default(false);
            }
            if (! Schema::hasColumn('employees', 'face_quality_score')) {
                $table->unsignedSmallInteger('face_quality_score')->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_meta')) {
                $table->json('face_meta')->nullable();
            }
        });

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
                $table->string('vendor', 80)->default('digitalpersona');
                $table->string('vendor_template_id', 191);
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->dateTime('deleted_at');
                $table->timestamps();
            });
            $this->createdTemplateDeletionsTable = true;
        }

        Schema::table('employee_template_deletions', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
                $table->string('biometric_type', 50)->nullable();
            }
        });

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
