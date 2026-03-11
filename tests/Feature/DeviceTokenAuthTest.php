<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeviceTokenAuthTest extends TestCase
{
    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdAttendanceLogsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['device.static_token' => 'TEST_DEVICE_TOKEN']);

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
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('clock_name')->default('Clock');
                $table->string('monitoring_status', 20)->default('offline');
                $table->string('program_status', 30)->default('offline');
                $table->timestamps();
            });
            $this->createdClocksTable = true;
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('status', 20)->default('A');
                $table->string('full_name')->nullable();
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

        DB::table('attendance_logs')->delete();
        DB::table('employees')->delete();
        DB::table('clocks')->delete();
        DB::table('locations')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdAttendanceLogsTable && Schema::hasTable('attendance_logs')) {
            Schema::drop('attendance_logs');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdClocksTable && Schema::hasTable('clocks')) {
            Schema::drop('clocks');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }

        parent::tearDown();
    }

    public function test_token_correcto_devuelve_200_en_device_ping(): void
    {
        $response = $this->postJson('/api/device/ping', [], [
            'Authorization' => 'Bearer TEST_DEVICE_TOKEN',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('device_auth', true);
    }

    public function test_sin_token_devuelve_401(): void
    {
        $response = $this->postJson('/api/device/ping');

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autorizado (device token).']);
    }

    public function test_token_incorrecto_devuelve_401(): void
    {
        $response = $this->postJson('/api/device/ping', [], [
            'Authorization' => 'Bearer INVALID_TOKEN',
        ]);

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autorizado (device token).']);
    }

    public function test_attendance_from_device_acepta_token_con_payload_valido(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit A', 'created_at' => now(), 'updated_at' => now()]);
        $clockId = DB::table('clocks')->insertGetId([
            'location_id' => $locationId,
            'clock_name' => 'Clock A',
            'monitoring_status' => 'offline',
            'program_status' => 'offline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 550001,
            'company_id' => 1,
            'base_location_id' => $locationId,
            'status' => 'A',
            'full_name' => 'Empleado Token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'location_id' => $locationId,
            'log_type' => 'IN',
            'log_date' => now()->toIso8601String(),
            'local_id' => 'DEV-TOKEN-ATT-001',
            'device_timestamp' => now()->toIso8601String(),
            'raw_payload' => ['source' => 'feature-test'],
        ];

        $response = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
            $payload,
            ['Authorization' => 'Bearer TEST_DEVICE_TOKEN']
        );

        $response->assertStatus(201)
            ->assertJsonPath('received', true);
    }

    public function test_login_authenticate_responde_json_con_accept_header(): void
    {
        $response = $this->post('/api/FortiaPrimeApi.Opensync/api/v2/login/authenticate', [
            'user' => 'diagnostic',
            'password' => 'diagnostic',
        ], [
            'Accept' => 'application/json',
        ]);

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }
}
