<?php

namespace Tests\Feature\Api;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeTemplatesSyncTest extends TestCase
{
    private const URI = '/api/FortiaPrimeApi.Opensync/api/v2/employees/templates';

    private bool $createdLocationsTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdTemplateDeletionsTable = false;

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
                $table->string('status', 20)->default('A');
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }

        if (! Schema::hasTable('employee_fingerprints')) {
            Schema::create('employee_fingerprints', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('vendor_template_id', 191)->nullable();
                $table->longText('template_b64')->nullable();
                $table->string('template_format', 40)->nullable();
                $table->string('status', 30)->default('enrolled');
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

        DB::table('employee_template_deletions')->delete();
        DB::table('employee_fingerprints')->delete();
        DB::table('employees')->delete();
        DB::table('locations')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdTemplateDeletionsTable && Schema::hasTable('employee_template_deletions')) {
            Schema::drop('employee_template_deletions');
        }
        if ($this->createdEmployeeFingerprintsTable && Schema::hasTable('employee_fingerprints')) {
            Schema::drop('employee_fingerprints');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }

        parent::tearDown();
    }

    public function test_sync_initial_returns_data_and_version(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit A']);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 801001,
            'base_location_id' => $locationId,
            'status' => 'A',
        ]);

        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-INIT-1',
            'template_b64' => base64_encode('init-template-1'),
            'template_format' => 'DPFP_PROPRIETARY',
            'status' => 'enrolled',
            'performed_at' => now()->subMinutes(5),
            'deleted_at' => null,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $response = $this->getJson(self::URI . '?status=all');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(0, 'tombstones');

        $version = $response->json('version');
        $this->assertMatchesRegularExpression('/^\d{14}$/', (string) $version);
    }

    public function test_sync_incremental_returns_only_newer_than_since(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit B']);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 801002,
            'base_location_id' => $locationId,
            'status' => 'A',
        ]);

        $oldTs = Carbon::now()->subDays(2);
        $newTs = Carbon::now()->subMinutes(2);

        DB::table('employee_fingerprints')->insert([
            [
                'employee_id' => $employeeId,
                'vendor_template_id' => 'TPL-OLD',
                'template_b64' => base64_encode('old-template'),
                'template_format' => 'DPFP_PROPRIETARY',
                'status' => 'enrolled',
                'performed_at' => $oldTs,
                'deleted_at' => null,
                'created_at' => $oldTs,
                'updated_at' => $oldTs,
            ],
            [
                'employee_id' => $employeeId,
                'vendor_template_id' => 'TPL-NEW',
                'template_b64' => base64_encode('new-template'),
                'template_format' => 'DPFP_PROPRIETARY',
                'status' => 'enrolled',
                'performed_at' => $newTs,
                'deleted_at' => null,
                'created_at' => $newTs,
                'updated_at' => $newTs,
            ],
        ]);

        $since = Carbon::now()->subDay()->format('YmdHis');
        $response = $this->getJson(self::URI . '?since=' . $since . '&status=all');

        $response->assertOk();
        $response->assertJsonMissing(['vendor_template_id' => 'TPL-OLD']);
        $response->assertJsonFragment(['vendor_template_id' => 'TPL-NEW']);
    }

    public function test_sync_tombstones_returns_deleted_since(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit C']);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 801003,
            'base_location_id' => $locationId,
            'status' => 'A',
        ]);

        DB::table('employee_template_deletions')->insert([
            [
                'vendor' => 'digitalpersona',
                'vendor_template_id' => 'TPL-DEL-OLD',
                'employee_id' => $employeeId,
                'deleted_at' => now()->subDays(4),
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ],
            [
                'vendor' => 'digitalpersona',
                'vendor_template_id' => 'TPL-DEL-NEW',
                'employee_id' => $employeeId,
                'deleted_at' => now()->subMinutes(5),
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ],
        ]);

        $since = now()->subDay()->toIso8601String();
        $response = $this->getJson(self::URI . '?since=' . urlencode($since) . '&status=all');

        $response->assertOk();
        $response->assertJsonMissing(['vendor_template_id' => 'TPL-DEL-OLD']);
        $response->assertJsonFragment(['vendor_template_id' => 'TPL-DEL-NEW']);
    }

    public function test_location_filter_works(): void
    {
        $locA = DB::table('locations')->insertGetId(['name' => 'Unit A']);
        $locB = DB::table('locations')->insertGetId(['name' => 'Unit B']);

        $empA = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 801004,
            'base_location_id' => $locA,
            'status' => 'A',
        ]);
        $empB = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 801005,
            'base_location_id' => $locB,
            'status' => 'A',
        ]);

        DB::table('employee_fingerprints')->insert([
            [
                'employee_id' => $empA,
                'vendor_template_id' => 'TPL-LOC-A',
                'template_b64' => base64_encode('loc-a'),
                'template_format' => 'DPFP_PROPRIETARY',
                'status' => 'enrolled',
                'performed_at' => now(),
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_id' => $empB,
                'vendor_template_id' => 'TPL-LOC-B',
                'template_b64' => base64_encode('loc-b'),
                'template_format' => 'DPFP_PROPRIETARY',
                'status' => 'enrolled',
                'performed_at' => now(),
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson(self::URI . '?location_id=' . $locA . '&status=all');

        $response->assertOk();
        $response->assertJsonFragment(['vendor_template_id' => 'TPL-LOC-A']);
        $response->assertJsonMissing(['vendor_template_id' => 'TPL-LOC-B']);
    }

    public function test_status_filter_works(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit Status']);
        $empActive = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 801006,
            'base_location_id' => $locationId,
            'status' => 'A',
        ]);
        $empInactive = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 801007,
            'base_location_id' => $locationId,
            'status' => 'B',
        ]);

        DB::table('employee_fingerprints')->insert([
            [
                'employee_id' => $empActive,
                'vendor_template_id' => 'TPL-ACTIVE',
                'template_b64' => base64_encode('active'),
                'template_format' => 'DPFP_PROPRIETARY',
                'status' => 'enrolled',
                'performed_at' => now(),
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_id' => $empInactive,
                'vendor_template_id' => 'TPL-INACTIVE',
                'template_b64' => base64_encode('inactive'),
                'template_format' => 'DPFP_PROPRIETARY',
                'status' => 'enrolled',
                'performed_at' => now(),
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $activeResp = $this->getJson(self::URI . '?status=active');
        $activeResp->assertOk();
        $activeResp->assertJsonFragment(['vendor_template_id' => 'TPL-ACTIVE']);
        $activeResp->assertJsonMissing(['vendor_template_id' => 'TPL-INACTIVE']);

        $inactiveResp = $this->getJson(self::URI . '?status=inactive');
        $inactiveResp->assertOk();
        $inactiveResp->assertJsonFragment(['vendor_template_id' => 'TPL-INACTIVE']);
        $inactiveResp->assertJsonMissing(['vendor_template_id' => 'TPL-ACTIVE']);
    }
}
