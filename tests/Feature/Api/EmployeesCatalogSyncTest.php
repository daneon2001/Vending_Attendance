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
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('status', 20)->default('A');
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }

        DB::table('employees')->delete();
        DB::table('locations')->delete();
    }

    protected function tearDown(): void
    {
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
            ->assertJsonCount(0, 'tombstones');

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
}
