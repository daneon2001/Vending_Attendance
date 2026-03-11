<?php

namespace Tests\Feature\Api;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceIdempotencyTest extends TestCase
{
    private const URI = '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device';

    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdAttendanceLogsTable = false;

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

        if (! Schema::hasTable('clocks')) {
            Schema::create('clocks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('clock_name')->default('Clock');
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

        if (! Schema::hasColumn('attendance_logs', 'local_id')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->string('local_id', 100)->nullable()->after('device_id');
            });
        }

        try {
            DB::statement('CREATE UNIQUE INDEX attendance_logs_local_device_unique ON attendance_logs(local_id, device_id)');
        } catch (\Throwable $e) {
            // index already exists
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

    public function test_store_from_device_creates_new_log_with_action_created(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit A']);
        $clockId = DB::table('clocks')->insertGetId(['location_id' => $locationId, 'clock_name' => 'Clock A']);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 990001,
            'company_id' => 1,
            'base_location_id' => $locationId,
            'status' => 'A',
        ]);

        $payload = [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'location_id' => $locationId,
            'log_type' => 'IN',
            'log_date' => now()->toIso8601String(),
            'local_id' => 'LID-ATT-001',
            'device_timestamp' => now()->toIso8601String(),
            'raw_payload' => ['source' => 'test'],
        ];

        $response = $this->postJson(self::URI, $payload);

        $response->assertStatus(201)
            ->assertJsonPath('received', true)
            ->assertJsonPath('action', 'CREATED');

        $cloudId = $response->json('cloud_id');
        $this->assertNotNull($cloudId);

        $this->assertDatabaseHas('attendance_logs', [
            'id' => $cloudId,
            'local_id' => 'LID-ATT-001',
            'device_id' => $clockId,
            'employee_id' => $employeeId,
        ]);
    }

    public function test_store_from_device_is_idempotent_for_same_local_id_and_clock(): void
    {
        $locationId = DB::table('locations')->insertGetId(['name' => 'Unit B']);
        $clockId = DB::table('clocks')->insertGetId(['location_id' => $locationId, 'clock_name' => 'Clock B']);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 990002,
            'company_id' => 1,
            'base_location_id' => $locationId,
            'status' => 'A',
        ]);

        $payload = [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'location_id' => $locationId,
            'log_type' => 'OUT',
            'log_date' => now()->toIso8601String(),
            'local_id' => 'LID-ATT-RETRY',
            'device_timestamp' => now()->toIso8601String(),
            'raw_payload' => ['source' => 'test-retry'],
        ];

        $first = $this->postJson(self::URI, $payload);
        $first->assertStatus(201)->assertJsonPath('action', 'CREATED');
        $cloudId = $first->json('cloud_id');

        $retry = $this->postJson(self::URI, $payload);
        $retry->assertStatus(200)
            ->assertJsonPath('received', true)
            ->assertJsonPath('action', 'ALREADY')
            ->assertJsonPath('cloud_id', $cloudId);

        $count = DB::table('attendance_logs')
            ->where('local_id', 'LID-ATT-RETRY')
            ->where('device_id', $clockId)
            ->count();

        $this->assertSame(1, $count);
    }
}
