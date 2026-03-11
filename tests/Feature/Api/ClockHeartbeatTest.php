<?php

namespace Tests\Feature\Api;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClockHeartbeatTest extends TestCase
{
    private bool $createdClocksTable = false;
    private bool $createdClockLogsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

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
        if (! Schema::hasColumn('clocks', 'last_seen_ip')) {
            Schema::table('clocks', function (Blueprint $table): void {
                $table->string('last_seen_ip', 45)->nullable()->after('last_status_message');
            });
        }

        if (! Schema::hasTable('clock_logs')) {
            Schema::create('clock_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('clock_id');
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

        DB::table('clock_logs')->delete();
        DB::table('clocks')->delete();
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

    public function test_clock_specific_heartbeat_updates_clock_and_returns_wrapper_payload(): void
    {
        $clockId = DB::table('clocks')->insertGetId([
            'clock_name' => 'Clock HB',
            'serial_number' => 'HB-001',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson(
            "/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/{$clockId}/heartbeat",
            [
                'monitoring_status' => 'online',
                'last_status_message' => 'Running OK',
                'program_status' => 'running',
                'device_timestamp' => now()->toIso8601String(),
                'pending_attendance' => 3,
                'pending_errors' => 1,
                'app_version' => 'checador-1.2.3',
                'ip_local' => '192.168.0.50',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('clock_id', $clockId)
            ->assertJsonPath('saved', true);

        $this->assertDatabaseHas('clocks', [
            'id' => $clockId,
            'monitoring_status' => 'online',
            'program_status' => 'online',
            'last_status_message' => 'Running OK',
            'last_seen_ip' => '192.168.0.50',
        ]);
    }
}
