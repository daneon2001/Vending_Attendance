<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeviceTokenMiddlewareTest extends TestCase
{
    private bool $createdClocksTable = false;
    private bool $createdClockLogsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function tearDown(): void
    {
        if ($this->createdClockLogsTable && Schema::hasTable('clock_logs')) {
            Schema::drop('clock_logs');
        }
        if ($this->createdClocksTable && Schema::hasTable('clocks')) {
            Schema::drop('clocks');
        }

        parent::tearDown();
    }

    public function test_attendance_endpoint_returns_401_without_authorization_header(): void
    {
        config(['device.static_token' => 'TEST_TOKEN']);

        $response = $this->postJson('/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device', []);

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autorizado (device token).']);
    }

    public function test_attendance_endpoint_returns_401_with_invalid_token(): void
    {
        config(['device.static_token' => 'TEST_TOKEN']);

        $response = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
            [],
            ['Authorization' => 'Bearer BAD_TOKEN']
        );

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autorizado (device token).']);
    }

    public function test_heartbeat_endpoint_passes_with_valid_device_token(): void
    {
        config(['device.static_token' => 'TEST_TOKEN']);

        $clockId = DB::table('clocks')->insertGetId([
            'clock_name' => 'Clock Device Token',
            'serial_number' => 'DT-001',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/'.$clockId.'/heartbeat',
            [],
            ['Authorization' => 'Bearer TEST_TOKEN']
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('clock_id', $clockId)
            ->assertJsonPath('saved', true);
    }

    public function test_device_endpoints_fail_when_static_token_is_not_configured(): void
    {
        config(['device.static_token' => '']);

        $response = $this->postJson(
            '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
            [],
            ['Authorization' => 'Bearer TEST_TOKEN']
        );

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'No autorizado (device token).']);
    }

    public function test_admin_login_endpoint_is_not_protected_by_device_token_middleware(): void
    {
        config(['device.static_token' => 'TEST_TOKEN']);

        $response = $this->postJson('/api/FortiaPrimeApi.Opensync/api/v2/login/authenticate', []);

        $this->assertNotSame(401, $response->status());
    }
}

