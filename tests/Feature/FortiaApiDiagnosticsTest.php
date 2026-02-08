<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FortiaApiDiagnosticsTest extends TestCase
{
    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdClockLogsTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdAttendanceLogsTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdTemplateDeletionsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['device.static_token' => 'TEST_TOKEN']);

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
        }

        if (! Schema::hasTable('clocks')) {
            Schema::create('clocks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('clock_name');
                $table->string('serial_number')->nullable();
                $table->string('firmware_version')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('type_inout', 50)->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->string('last_status_message')->nullable();
                $table->string('last_seen_ip', 45)->nullable();
                $table->string('monitoring_status', 20)->default('offline');
                $table->string('program_status', 30)->default('offline');
                $table->timestamps();
            });
            $this->createdClocksTable = true;
        }

        if (! Schema::hasTable('clock_logs')) {
            Schema::create('clock_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('event_type', 50);
                $table->string('level', 20)->default('info');
                $table->string('source', 120)->nullable();
                $table->string('message')->nullable();
                $table->json('payload')->nullable();
                $table->dateTime('occurred_at')->nullable();
                $table->timestamps();
            });
            $this->createdClockLogsTable = true;
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('status', 20)->default('A');
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('full_name')->nullable();
                $table->boolean('has_fingerprint')->default(false);
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }

        if (! Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('log_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('fortia_employee_id')->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('local_id', 100)->nullable();
                $table->dateTime('log_date');
                $table->string('log_type', 20)->default('IN');
                $table->json('raw_payload')->nullable();
                $table->timestamps();
                $table->unique(['local_id', 'device_id'], 'attendance_logs_local_device_unique');
            });
            $this->createdAttendanceLogsTable = true;
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

        DB::table('attendance_logs')->delete();
        DB::table('clock_logs')->delete();
        DB::table('employee_fingerprints')->delete();
        DB::table('employee_template_deletions')->delete();
        DB::table('employees')->delete();
        DB::table('clocks')->delete();
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
        if ($this->createdAttendanceLogsTable && Schema::hasTable('attendance_logs')) {
            Schema::drop('attendance_logs');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdClockLogsTable && Schema::hasTable('clock_logs')) {
            Schema::drop('clock_logs');
        }
        if ($this->createdClocksTable && Schema::hasTable('clocks')) {
            Schema::drop('clocks');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }

        parent::tearDown();
    }

    public function test_device_token_rejects_missing(): void
    {
        $response = $this->postJson('/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device', []);

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autorizado (device token).']);
    }

    public function test_device_token_rejects_invalid(): void
    {
        $response = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
            [],
            ['Authorization' => 'Bearer BAD_TOKEN']
        );

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autorizado (device token).']);
    }

    public function test_heartbeat_accepts_valid_token(): void
    {
        $clockId = DB::table('clocks')->insertGetId([
            'clock_name' => 'Clock A',
            'serial_number' => 'SER-A',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/'.$clockId.'/heartbeat',
            [
                'monitoring_status' => 'online',
                'last_status_message' => 'OK',
                'program_status' => 'running',
                'device_timestamp' => now()->toIso8601String(),
            ],
            ['Authorization' => 'Bearer TEST_TOKEN']
        );

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('clocks', ['id' => $clockId, 'monitoring_status' => 'online']);
    }

    public function test_attendance_created_with_token(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit A', 'created_at' => now(), 'updated_at' => now()]);
        $clockId = DB::table('clocks')->insertGetId(['location_id' => $locationId, 'clock_name' => 'Clock A', 'created_at' => now(), 'updated_at' => now()]);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 123001,
            'company_id' => 1,
            'base_location_id' => $locationId,
            'status' => 'A',
            'full_name' => 'Empleado A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'location_id' => $locationId,
            'log_type' => 'IN',
            'log_date' => now()->toIso8601String(),
            'local_id' => 'FT-DIAG-001',
            'device_timestamp' => now()->toIso8601String(),
            'raw_payload' => ['source' => 'test'],
        ];

        $response = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
            $payload,
            ['Authorization' => 'Bearer TEST_TOKEN']
        );

        $response->assertStatus(201)
            ->assertJsonPath('received', true)
            ->assertJsonPath('action', 'CREATED');
    }

    public function test_attendance_idempotent_already(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit B', 'created_at' => now(), 'updated_at' => now()]);
        $clockId = DB::table('clocks')->insertGetId(['location_id' => $locationId, 'clock_name' => 'Clock B', 'created_at' => now(), 'updated_at' => now()]);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 123002,
            'company_id' => 1,
            'base_location_id' => $locationId,
            'status' => 'A',
            'full_name' => 'Empleado B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'location_id' => $locationId,
            'log_type' => 'OUT',
            'log_date' => now()->toIso8601String(),
            'local_id' => 'FT-DIAG-RETRY',
            'device_timestamp' => now()->toIso8601String(),
            'raw_payload' => ['source' => 'test'],
        ];

        $first = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
            $payload,
            ['Authorization' => 'Bearer TEST_TOKEN']
        );
        $first->assertStatus(201)->assertJsonPath('action', 'CREATED');

        $retry = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
            $payload,
            ['Authorization' => 'Bearer TEST_TOKEN']
        );
        $retry->assertStatus(200)->assertJsonPath('action', 'ALREADY');
    }

    public function test_templates_endpoint_responds(): void
    {
        $response = $this->getJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/employees/templates',
            ['Authorization' => 'Bearer TEST_TOKEN']
        );

        $response->assertOk()
            ->assertJsonStructure(['version', 'data', 'tombstones']);
    }
}
