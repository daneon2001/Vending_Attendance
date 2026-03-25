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
    private bool $createdEmployeeScopeDeletionsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

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
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->boolean('can_check_all_branches')->default(false);
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('status', 20)->default('A');
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
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
        DB::table('employees')->delete();
        DB::table('locations')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdEmployeeScopeDeletionsTable && Schema::hasTable('employee_scope_deletions')) {
            Schema::drop('employee_scope_deletions');
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
