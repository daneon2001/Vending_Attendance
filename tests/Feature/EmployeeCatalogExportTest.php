<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckTokenExpiration;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class EmployeeCatalogExportTest extends TestCase
{
    private bool $createdUsersTable = false;

    private bool $createdRolesTable = false;

    private bool $createdRoleUserTable = false;

    private bool $createdPermissionsTable = false;

    private bool $createdPermissionRoleTable = false;

    private bool $createdLocationsTable = false;

    private bool $createdEmployeesTable = false;

    private bool $createdEmployeeAllowedLocationsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([HandleInertiaRequests::class, CheckTokenExpiration::class]);
        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        if ($this->createdEmployeeAllowedLocationsTable && Schema::hasTable('employee_allowed_locations')) {
            Schema::drop('employee_allowed_locations');
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

    public function test_export_requires_authentication(): void
    {
        $response = $this->get(route('employees.catalog.export'));

        $response->assertRedirect(route('login'));
    }

    public function test_export_requires_employee_view_permission(): void
    {
        $this->authenticate(withPermission: false);

        $response = $this->get(route('employees.catalog.export'));

        $response->assertForbidden();
    }

    public function test_export_downloads_filtered_employees_for_assigned_base_location_only(): void
    {
        $this->authenticate();

        $locationA = DB::table('locations')->insertGetId([
            'fortia_location_id' => 701,
            'code' => '701',
            'name' => 'Unidad Export A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locationB = DB::table('locations')->insertGetId([
            'fortia_location_id' => 702,
            'code' => '702',
            'name' => 'Unidad Export B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 98001,
                'full_name' => 'Base Empleado',
                'name' => 'Base',
                'last_name' => 'Empleado',
                'company_name' => 'Medical Life',
                'base_location_id' => 701,
                'base_location_name' => 'Unidad Export A',
                'status' => 'A',
                'has_fingerprint' => true,
                'has_face_enrollment' => false,
                'can_check_all_branches' => false,
                'check_scope' => 'HOME_ONLY',
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(1),
            ],
            [
                'fortia_employee_id' => 98002,
                'full_name' => 'Permitido Empleado',
                'name' => 'Permitido',
                'last_name' => 'Empleado',
                'company_name' => 'Medical Life',
                'base_location_id' => 702,
                'base_location_name' => 'Unidad Export B',
                'status' => 'A',
                'has_fingerprint' => false,
                'has_face_enrollment' => true,
                'can_check_all_branches' => false,
                'check_scope' => 'SELECTED_BRANCHES',
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subHours(8),
            ],
            [
                'fortia_employee_id' => 98003,
                'full_name' => 'Global Empleado',
                'name' => 'Global',
                'last_name' => 'Empleado',
                'company_name' => 'Medical Life',
                'base_location_id' => 701,
                'base_location_name' => 'Unidad Export A',
                'status' => 'A',
                'has_fingerprint' => true,
                'has_face_enrollment' => true,
                'can_check_all_branches' => true,
                'check_scope' => 'ANY_BRANCH',
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subHours(4),
            ],
            [
                'fortia_employee_id' => 98004,
                'full_name' => 'Fuera Empleado',
                'name' => 'Fuera',
                'last_name' => 'Empleado',
                'company_name' => 'Medical Life',
                'base_location_id' => 702,
                'base_location_name' => 'Unidad Export B',
                'status' => 'A',
                'has_fingerprint' => false,
                'has_face_enrollment' => false,
                'can_check_all_branches' => false,
                'check_scope' => 'HOME_ONLY',
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subHours(2),
            ],
        ]);

        $allowedEmployeeId = DB::table('employees')
            ->where('fortia_employee_id', 98002)
            ->value('id');

        DB::table('employee_allowed_locations')->insert([
            'employee_id' => $allowedEmployeeId,
            'location_id' => $locationA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('employees.catalog.export', [
            'location_id' => $locationA,
            'page' => 3,
            'per_page' => 1,
        ]));

        $response->assertOk();
        $response->assertDownload();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));

        $rows = $this->exportRows($response);
        $employeeKeys = collect(array_slice($rows, 1))
            ->map(static fn (array $row) => $row[0] ?? null)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['98001', '98003'], $employeeKeys);
        $this->assertNotContains('98002', $employeeKeys);
        $this->assertNotContains('98004', $employeeKeys);
    }

    public function test_export_applies_search_filter_and_does_not_depend_on_current_page(): void
    {
        $this->authenticate();

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 98101,
                'full_name' => 'Alicia Exportable',
                'name' => 'Alicia',
                'last_name' => 'Exportable',
                'company_name' => 'Medical Life',
                'status' => 'A',
                'has_fingerprint' => true,
                'has_face_enrollment' => false,
                'can_check_all_branches' => false,
                'check_scope' => 'HOME_ONLY',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subHour(),
            ],
            [
                'fortia_employee_id' => 98102,
                'full_name' => 'Bruno No Coincide',
                'name' => 'Bruno',
                'last_name' => 'No Coincide',
                'company_name' => 'Medical Life',
                'status' => 'A',
                'has_fingerprint' => false,
                'has_face_enrollment' => false,
                'can_check_all_branches' => false,
                'check_scope' => 'HOME_ONLY',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subHour(),
            ],
        ]);

        $response = $this->get(route('employees.catalog.export', [
            'q' => 'Alicia',
            'page' => 7,
            'per_page' => 1,
        ]));

        $response->assertOk();
        $response->assertDownload();

        $rows = $this->exportRows($response);
        $employeeNames = collect(array_slice($rows, 1))
            ->map(static fn (array $row) => $row[1] ?? null)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['Alicia Exportable'], $employeeNames);
    }

    private function authenticate(bool $withPermission = true): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Export Admin',
            'email' => sprintf('export-admin-%s@example.test', uniqid()),
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

        DB::table('role_user')->insert([
            'role_id' => $roleId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withPermission) {
            $permissionId = DB::table('permissions')->insertGetId([
                'module' => 'employees',
                'action' => 'view',
                'name' => 'employees.view',
                'description' => 'Permiso de prueba',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('permission_role')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs(User::query()->findOrFail($userId));
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function exportRows($response): array
    {
        $binaryResponse = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binaryResponse);

        $path = $binaryResponse->getFile()->getPathname();
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
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
                $table->unsignedBigInteger('fortia_location_id')->nullable();
                $table->string('code')->nullable();
                $table->string('name')->nullable();
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
        }

        Schema::table('locations', function (Blueprint $table): void {
            if (! Schema::hasColumn('locations', 'fortia_location_id')) {
                $table->unsignedBigInteger('fortia_location_id')->nullable();
            }
            if (! Schema::hasColumn('locations', 'code')) {
                $table->string('code')->nullable();
            }
        });

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->nullable()->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('company_name')->nullable();
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
            if (! Schema::hasColumn('employees', 'can_check_all_branches')) {
                $table->boolean('can_check_all_branches')->default(false);
            }
            if (! Schema::hasColumn('employees', 'check_scope')) {
                $table->string('check_scope', 40)->nullable();
            }
            if (! Schema::hasColumn('employees', 'has_face_enrollment')) {
                $table->boolean('has_face_enrollment')->default(false);
            }
            if (! Schema::hasColumn('employees', 'employee_code')) {
                $table->string('employee_code')->nullable();
            }
            if (! Schema::hasColumn('employees', 'code')) {
                $table->string('code')->nullable();
            }
            if (! Schema::hasColumn('employees', 'clave_empleado')) {
                $table->string('clave_empleado')->nullable();
            }
        });

        if (! Schema::hasTable('employee_allowed_locations')) {
            Schema::create('employee_allowed_locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('location_id');
                $table->timestamps();
            });
            $this->createdEmployeeAllowedLocationsTable = true;
        }
    }

    private function cleanData(): void
    {
        if (Schema::hasTable('employee_allowed_locations')) {
            DB::table('employee_allowed_locations')->delete();
        }

        if (Schema::hasTable('employees')) {
            DB::table('employees')->delete();
        }

        if (Schema::hasTable('locations')) {
            DB::table('locations')->delete();
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

        if (Schema::hasTable('users')) {
            DB::table('users')->delete();
        }
    }
}
