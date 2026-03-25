<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\CheckTokenExpiration;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeCompactApiTest extends TestCase
{
    private const URI = '/api/admin/employees';

    private bool $createdUsersTable = false;
    private bool $createdRolesTable = false;
    private bool $createdRoleUserTable = false;
    private bool $createdPermissionsTable = false;
    private bool $createdPermissionRoleTable = false;
    private bool $createdLocationsTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(HandleInertiaRequests::class);
        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        if ($this->createdEmployeeFingerprintsTable && Schema::hasTable('employee_fingerprints')) {
            Schema::drop('employee_fingerprints');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }
        if ($this->createdUsersTable && Schema::hasTable('users')) {
            Schema::drop('users');
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

        parent::tearDown();
    }

    public function test_compact_endpoint_requires_authentication(): void
    {
        $response = $this->getJson(self::URI);

        $response->assertUnauthorized();
    }

    public function test_compact_endpoint_returns_ok_meta_and_without_heavy_fields(): void
    {
        $this->withoutMiddleware([EnsurePermission::class, CheckTokenExpiration::class]);
        $this->authenticate();

        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Unidad Norte',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        for ($i = 1; $i <= 20; $i++) {
            $employeeId = DB::table('employees')->insertGetId([
                'fortia_employee_id' => 8000 + $i,
                'name' => 'Nombre '.$i,
                'last_name' => 'Apellido',
                'full_name' => 'Nombre '.$i.' Apellido',
                'base_location_id' => $locationId,
                'base_location_name' => 'Unidad Norte',
                'status' => $i % 2 === 0 ? 'A' : 'B',
                'has_fingerprint' => $i % 2 === 0,
                'created_at' => $now->copy()->subMinutes($i),
                'updated_at' => $now->copy()->subMinutes($i),
            ]);

            DB::table('employee_fingerprints')->insert([
                'employee_id' => $employeeId,
                'vendor_template_id' => 'TPL-'.$i,
                'template_b64' => str_repeat(base64_encode('template-'.$i), 600),
                'status' => 'enrolled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->getJson(self::URI.'?page=1&per_page=15');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(15, 'data');

        $payload = (string) $response->getContent();
        $this->assertIsBool($response->json('data.0.has_fingerprint'));
        $this->assertIsString($response->json('data.0.fingerprint_status'));
        $this->assertIsBool($response->json('data.0.has_face_enrollment'));
        $this->assertIsBool($response->json('data.0.face_sync_ready'));

        $this->assertStringNotContainsStringIgnoringCase('template_b64', $payload);
        $this->assertStringNotContainsStringIgnoringCase('base64', $payload);
        $this->assertLessThan(50 * 1024, strlen($payload), 'Compact payload exceeded 50KB target.');
    }

    public function test_compact_endpoint_applies_filters_and_sorting(): void
    {
        $this->withoutMiddleware([EnsurePermission::class, CheckTokenExpiration::class]);
        $this->authenticate();

        $unitA = DB::table('locations')->insertGetId([
            'name' => 'Unidad A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitB = DB::table('locations')->insertGetId([
            'name' => 'Unidad B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 91001,
                'name' => 'Ana',
                'last_name' => 'Ruiz',
                'full_name' => 'Ana Ruiz',
                'base_location_id' => $unitA,
                'base_location_name' => 'Unidad A',
                'status' => 'A',
                'has_fingerprint' => true,
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'fortia_employee_id' => 91002,
                'name' => 'Bruno',
                'last_name' => 'Lopez',
                'full_name' => 'Bruno Lopez',
                'base_location_id' => $unitA,
                'base_location_name' => 'Unidad A',
                'status' => 'B',
                'has_fingerprint' => false,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'fortia_employee_id' => 92001,
                'name' => 'Carla',
                'last_name' => 'Mora',
                'full_name' => 'Carla Mora',
                'base_location_id' => $unitB,
                'base_location_name' => 'Unidad B',
                'status' => 'A',
                'has_fingerprint' => false,
                'created_at' => now()->subHours(8),
                'updated_at' => now()->subHours(8),
            ],
        ]);

        $response = $this->getJson(self::URI.'?per_page=50&q=9100&unit_id='.$unitA.'&status=ACTIVE&sort_by=updated_at&sort_dir=desc');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', '91001')
            ->assertJsonPath('data.0.full_name', 'Ana Ruiz')
            ->assertJsonPath('data.0.unit_id', $unitA)
            ->assertJsonPath('data.0.status', 'ACTIVE');
    }

    public function test_compact_endpoint_filters_face_enrollment_and_sync_ready(): void
    {
        $this->withoutMiddleware([EnsurePermission::class, CheckTokenExpiration::class]);
        $this->authenticate();

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 93001,
                'name' => 'Face',
                'last_name' => 'Lista',
                'full_name' => 'Face Lista',
                'status' => 'A',
                'has_fingerprint' => false,
                'has_face_enrollment' => true,
                'face_status' => 'enrolled',
                'face_enabled' => true,
                'face_samples_count' => 3,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ],
            [
                'fortia_employee_id' => 93002,
                'name' => 'Face',
                'last_name' => 'Deshabilitada',
                'full_name' => 'Face Deshabilitada',
                'status' => 'A',
                'has_fingerprint' => false,
                'has_face_enrollment' => true,
                'face_status' => 'disabled',
                'face_enabled' => false,
                'face_samples_count' => 2,
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ],
            [
                'fortia_employee_id' => 93003,
                'name' => 'Sin',
                'last_name' => 'Face',
                'full_name' => 'Sin Face',
                'status' => 'A',
                'has_fingerprint' => false,
                'has_face_enrollment' => false,
                'face_status' => 'none',
                'face_enabled' => false,
                'face_samples_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $withFaceResponse = $this->getJson(self::URI.'?face=with&sync_ready=1&per_page=50');

        $withFaceResponse->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', '93001')
            ->assertJsonPath('data.0.face_sync_ready', true);

        $withoutFaceResponse = $this->getJson(self::URI.'?face=without&per_page=50');

        $withoutFaceResponse->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', '93003');
    }

    public function test_compact_endpoint_stays_below_100kb_for_15_items_and_never_exposes_template_b64(): void
    {
        $this->withoutMiddleware([EnsurePermission::class, CheckTokenExpiration::class]);
        $this->authenticate();

        $now = now();
        for ($i = 1; $i <= 15; $i++) {
            $employeeId = DB::table('employees')->insertGetId([
                'fortia_employee_id' => 99000 + $i,
                'name' => 'Carga '.$i,
                'last_name' => 'Ligera',
                'full_name' => 'Carga '.$i.' Ligera',
                'status' => 'A',
                'has_fingerprint' => true,
                'created_at' => $now->copy()->subMinutes($i),
                'updated_at' => $now->copy()->subMinutes($i),
            ]);

            DB::table('employee_fingerprints')->insert([
                'employee_id' => $employeeId,
                'vendor_template_id' => 'TPL-COMPACT-'.$i,
                'template_b64' => str_repeat(base64_encode('very-large-template-'.$i), 1200),
                'status' => 'enrolled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->getJson(self::URI.'?page=1&per_page=15');

        $response->assertOk()->assertJsonCount(15, 'data');

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('template_b64', $payload);
        $this->assertLessThan(100 * 1024, strlen($payload), 'Compact payload exceeded 100KB.');
    }

    private function authenticate(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Compact Admin',
            'email' => 'compact-admin@example.test',
            'password' => bcrypt('password'),
            'estatus' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Administrador',
            'description' => 'Rol de prueba',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->insertGetId([
            'module' => 'employees',
            'action' => 'view',
            'name' => 'employees.view',
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

        $this->actingAs(User::query()->findOrFail($userId));
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

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
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
                $table->string('status', 30)->default('enrolled');
                $table->timestamps();
            });
            $this->createdEmployeeFingerprintsTable = true;
        }
    }

    private function cleanData(): void
    {
        if (Schema::hasTable('employee_fingerprints')) {
            DB::table('employee_fingerprints')->delete();
        }

        if (Schema::hasTable('employees')) {
            DB::table('employees')->delete();
        }

        if (Schema::hasTable('locations')) {
            DB::table('locations')->delete();
        }

        if (Schema::hasTable('users')) {
            DB::table('users')->delete();
        }

        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->delete();
        }

        if (Schema::hasTable('role_user')) {
            DB::table('role_user')->delete();
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')->delete();
        }
    }
}
