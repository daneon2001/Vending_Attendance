<?php

namespace Tests\Feature\Api;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeesCatalogSyncTest extends TestCase
{
    private const URI = '/api/FortiaPrimeApi.Opensync/api/v2/employees/catalog';

    private bool $createdLocationsTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeAllowedLocationsTable = false;
    private bool $createdEmployeeScopeDeletionsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

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
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->boolean('can_check_all_branches')->default(false);
                $table->string('check_scope', 40)->nullable();
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('status', 20)->default('A');
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'check_scope')) {
                $table->string('check_scope', 40)->nullable();
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

        if (! Schema::hasTable('employee_scope_deletions')) {
            Schema::create('employee_scope_deletions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('scope_location_id');
                $table->dateTime('deleted_at');
                $table->string('reason', 80)->nullable();
                $table->timestamps();
            });
            $this->createdEmployeeScopeDeletionsTable = true;
        }

        DB::table('employee_scope_deletions')->delete();
        DB::table('employee_allowed_locations')->delete();
        DB::table('employees')->delete();
        DB::table('locations')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdEmployeeScopeDeletionsTable && Schema::hasTable('employee_scope_deletions')) {
            Schema::drop('employee_scope_deletions');
        }
        if ($this->createdEmployeeAllowedLocationsTable && Schema::hasTable('employee_allowed_locations')) {
            Schema::drop('employee_allowed_locations');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }

        parent::tearDown();
    }

    public function test_catalog_returns_version_and_active_data(): void
    {
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Unit 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'fortia_employee_id' => 7001,
            'base_location_id' => $locationId,
            'name' => 'Ana',
            'last_name' => 'Lugo',
            'full_name' => 'Ana Lugo',
            'status' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(self::URI.'?location_id='.$locationId);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(0, 'tombstones')
            ->assertJsonPath('data.0.can_check_all_branches', false);

        $version = $response->json('version');
        $this->assertMatchesRegularExpression('/^\d{14}$/', (string) $version);
    }

    public function test_catalog_since_filters_data_and_tombstones(): void
    {
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Unit 2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldTs = Carbon::now()->subDays(2);
        $newTs = Carbon::now()->subMinutes(10);

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 7002,
                'base_location_id' => $locationId,
                'name' => 'Activo',
                'last_name' => 'Nuevo',
                'full_name' => 'Activo Nuevo',
                'status' => 'A',
                'created_at' => $newTs,
                'updated_at' => $newTs,
            ],
            [
                'fortia_employee_id' => 7003,
                'base_location_id' => $locationId,
                'name' => 'Inactivo',
                'last_name' => 'Viejo',
                'full_name' => 'Inactivo Viejo',
                'status' => 'B',
                'created_at' => $newTs,
                'updated_at' => $newTs,
            ],
            [
                'fortia_employee_id' => 7004,
                'base_location_id' => $locationId,
                'name' => 'Activo',
                'last_name' => 'Viejo',
                'full_name' => 'Activo Viejo',
                'status' => 'A',
                'created_at' => $oldTs,
                'updated_at' => $oldTs,
            ],
        ]);

        $since = Carbon::now()->subDay()->format('YmdHis');
        $response = $this->getJson(self::URI.'?since='.$since.'&location_id='.$locationId);

        $response->assertOk();
        $response->assertJsonFragment(['fortia_employee_id' => 7002]);
        $response->assertJsonMissing(['fortia_employee_id' => 7004]);
        $response->assertJsonCount(1, 'tombstones');
        $response->assertJsonPath('tombstones.0.employee_id', DB::table('employees')->where('fortia_employee_id', 7003)->value('id'));
    }

    public function test_catalog_accepts_internal_location_id_when_employee_base_location_uses_fortia_key(): void
    {
        $locationInternalA = DB::table('locations')->insertGetId([
            'fortia_location_id' => 501,
            'code' => '501',
            'name' => 'Unit Fortia A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('locations')->insert([
            'fortia_location_id' => 502,
            'code' => '502',
            'name' => 'Unit Fortia B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 7701,
                'base_location_id' => 501,
                'name' => 'Mar',
                'last_name' => 'Uno',
                'full_name' => 'Mar Uno',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fortia_employee_id' => 7702,
                'base_location_id' => 502,
                'name' => 'Mar',
                'last_name' => 'Dos',
                'full_name' => 'Mar Dos',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson(self::URI.'?location_id='.$locationInternalA);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fortia_employee_id', 7701)
            ->assertJsonPath('data.0.base_location_id', 501)
            ->assertJsonPath('data.0.location_id', 501);
    }

    public function test_catalog_includes_current_branch_and_multibranch_global_employees(): void
    {
        $locA = DB::table('locations')->insertGetId([
            'name' => 'Unit A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locB = DB::table('locations')->insertGetId([
            'name' => 'Unit B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 7101,
                'base_location_id' => $locA,
                'can_check_all_branches' => false,
                'name' => 'Local',
                'last_name' => 'Activo',
                'full_name' => 'Local Activo',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fortia_employee_id' => 7102,
                'base_location_id' => $locB,
                'can_check_all_branches' => true,
                'name' => 'Global',
                'last_name' => 'Activo',
                'full_name' => 'Global Activo',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fortia_employee_id' => 7103,
                'base_location_id' => $locB,
                'can_check_all_branches' => false,
                'name' => 'Otro',
                'last_name' => 'Activo',
                'full_name' => 'Otro Activo',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fortia_employee_id' => 7104,
                'base_location_id' => $locB,
                'can_check_all_branches' => true,
                'name' => 'Global',
                'last_name' => 'Inactivo',
                'full_name' => 'Global Inactivo',
                'status' => 'B',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson(self::URI.'?location_id='.$locA);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment([
            'fortia_employee_id' => 7101,
            'can_check_all_branches' => false,
        ]);
        $response->assertJsonFragment([
            'fortia_employee_id' => 7102,
            'can_check_all_branches' => true,
        ]);
        $response->assertJsonMissing(['fortia_employee_id' => 7103]);
        $response->assertJsonMissing(['fortia_employee_id' => 7104]);
        $response->assertJsonCount(1, 'tombstones');
        $response->assertJsonPath(
            'tombstones.0.employee_id',
            DB::table('employees')->where('fortia_employee_id', 7104)->value('id')
        );
    }

    public function test_catalog_includes_selected_branch_employees_even_when_check_scope_is_desynced(): void
    {
        $locA = DB::table('locations')->insertGetId([
            'name' => 'Unit Allowed A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locB = DB::table('locations')->insertGetId([
            'name' => 'Unit Allowed B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $selectedEmployeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 7151,
            'base_location_id' => $locB,
            'can_check_all_branches' => false,
            'check_scope' => 'HOME_ONLY',
            'name' => 'Permitido',
            'last_name' => 'Seleccionado',
            'full_name' => 'Permitido Seleccionado',
            'status' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_allowed_locations')->insert([
            'employee_id' => $selectedEmployeeId,
            'location_id' => $locA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'fortia_employee_id' => 7152,
            'base_location_id' => $locB,
            'can_check_all_branches' => false,
            'check_scope' => 'HOME_ONLY',
            'name' => 'Fuera',
            'last_name' => 'Scope',
            'full_name' => 'Fuera Scope',
            'status' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(self::URI.'?location_id='.$locA);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fortia_employee_id', 7151)
            ->assertJsonPath('data.0.check_scope', 'HOME_ONLY')
            ->assertJsonPath('data.0.allowed_location_ids.0', $locA);
    }

    public function test_catalog_includes_global_scope_employees_even_when_check_scope_is_desynced(): void
    {
        $locA = DB::table('locations')->insertGetId([
            'name' => 'Unit Global A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locB = DB::table('locations')->insertGetId([
            'name' => 'Unit Global B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 7161,
                'base_location_id' => $locB,
                'can_check_all_branches' => true,
                'check_scope' => 'HOME_ONLY',
                'name' => 'Global',
                'last_name' => 'Desalineado',
                'full_name' => 'Global Desalineado',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fortia_employee_id' => 7162,
                'base_location_id' => $locB,
                'can_check_all_branches' => false,
                'check_scope' => 'HOME_ONLY',
                'name' => 'Local',
                'last_name' => 'B',
                'full_name' => 'Local B',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson(self::URI.'?location_id='.$locA);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fortia_employee_id', 7161)
            ->assertJsonPath('data.0.can_check_all_branches', true)
            ->assertJsonPath('data.0.check_scope', 'HOME_ONLY');
    }

    public function test_catalog_returns_scope_tombstones_when_employee_leaves_branch(): void
    {
        $locA = DB::table('locations')->insertGetId([
            'name' => 'Unit Scope A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locB = DB::table('locations')->insertGetId([
            'name' => 'Unit Scope B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 7201,
            'base_location_id' => $locB,
            'can_check_all_branches' => false,
            'name' => 'Cambio',
            'last_name' => 'Sucursal',
            'full_name' => 'Cambio Sucursal',
            'status' => 'A',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now(),
        ]);

        DB::table('employee_scope_deletions')->insert([
            'employee_id' => $employeeId,
            'scope_location_id' => $locA,
            'deleted_at' => now(),
            'reason' => 'scope_lost',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(self::URI.'?location_id='.$locA);

        $response->assertOk();
        $response->assertJsonMissing(['fortia_employee_id' => 7201]);
        $response->assertJsonFragment([
            'employee_id' => $employeeId,
        ]);
    }
}
