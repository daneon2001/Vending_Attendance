<?php

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnPremDiagnosticsCommandTest extends TestCase
{
    private bool $createdDevicesTable = false;
    private bool $createdDeviceNoncesTable = false;
    private bool $createdLocationsTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdAttendancesRawTable = false;
    private bool $createdClocksTable = false;
    private int $expectedClockId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();
        $this->cleanData();
        $this->assertTrue(
            Schema::hasColumns('clocks', ['id', 'clock_name', 'serial_number', 'last_heartbeat_at', 'monitoring_status']),
            'OnPrem diagnostic fixture requires the clocks lookup and heartbeat schema.'
        );
        // The diagnostic selects an existing clock inside its rollback-only transaction.
        $this->expectedClockId = DB::table('clocks')->insertGetId([
            'clock_name' => 'Synthetic diagnostic clock',
            'serial_number' => 'FIXTURE-ONPREM-CLOCK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->deleteReportFile();
    }

    protected function tearDown(): void
    {
        $this->deleteReportFile();

        if ($this->createdAttendancesRawTable && Schema::hasTable('attendances_raw')) {
            Schema::drop('attendances_raw');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }
        if ($this->createdDeviceNoncesTable && Schema::hasTable('device_nonces')) {
            Schema::drop('device_nonces');
        }
        if ($this->createdDevicesTable && Schema::hasTable('devices')) {
            Schema::drop('devices');
        }
        if ($this->createdClocksTable && Schema::hasTable('clocks')) {
            Schema::drop('clocks');
        }

        parent::tearDown();
    }

    public function test_onprem_diagnostics_command_passes_and_generates_json_report(): void
    {
        $this->artisan('fortia:diagnose-onprem')
            ->assertExitCode(0);

        $reportPath = storage_path('app/onprem_diagnostics.json');
        $this->assertFileExists($reportPath);

        $report = json_decode((string) File::get($reportPath), true);
        $this->assertIsArray($report);
        $this->assertSame(0, (int) ($report['summary']['failed'] ?? -1));

        $checks = collect($report['checks'] ?? [])->keyBy('name');
        $this->assertSame(
            $this->expectedClockId,
            data_get($checks->get('e2e.heartbeat.200'), 'extra.json.device.clock_id'),
            'The signed diagnostic heartbeat must resolve the synthetic fixture clock.'
        );
        $this->assertDatabaseHas('clocks', [
            'id' => $this->expectedClockId,
            'monitoring_status' => 'offline',
            'last_heartbeat_at' => null,
        ]); // Diagnostic updates must have rolled back.
        foreach ([
            'route.exists.ping',
            'route.exists.heartbeat',
            'route.exists.attendances',
            'middleware.ping.has_hmac',
            'middleware.heartbeat.has_hmac',
            'middleware.attendances.has_hmac',
            'middleware.ping.no_sanctum',
            'middleware.heartbeat.no_sanctum',
            'middleware.attendances.no_sanctum',
            'e2e.ping.200',
            'e2e.heartbeat.200',
            'e2e.attendances.200',
        ] as $checkName) {
            $this->assertSame('PASS', $checks->get($checkName)['status'] ?? null, "Check {$checkName} did not PASS.");
        }
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('clocks')) {
            // Minimal subset of create_clocks + monitoring/program-status migrations.
            // No company/location columns: this diagnostic fixture does not model those FKs.
            Schema::create('clocks', function (Blueprint $table): void {
                $table->id();
                $table->string('clock_name');
                $table->string('serial_number')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable()->index();
                $table->string('last_status_message')->nullable();
                $table->string('monitoring_status', 20)->default('offline');
                $table->string('program_status', 30)->default('offline');
                $table->timestamps();
            });
            $this->createdClocksTable = true;
        }
        if (! Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table): void {
                $table->id();
                $table->string('device_serial', 120)->unique();
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('shared_secret', 255);
                $table->boolean('is_active')->default(true);
                $table->dateTime('last_seen_at')->nullable();
                $table->dateTime('last_heartbeat_at')->nullable();
                $table->string('last_status', 500)->nullable();
                $table->timestamps();
            });
            $this->createdDevicesTable = true;
        }

        if (! Schema::hasTable('device_nonces')) {
            Schema::create('device_nonces', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('nonce', 120);
                $table->dateTime('seen_at');
                $table->dateTime('expires_at');
                $table->timestamps();
                $table->unique(['device_id', 'nonce'], 'device_nonces_device_nonce_unique');
            });
            $this->createdDeviceNoncesTable = true;
        }

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('name');
                $table->string('code', 30)->nullable();
                $table->string('timezone', 60)->nullable();
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->nullable()->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
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

        if (! Schema::hasTable('attendances_raw')) {
            Schema::create('attendances_raw', function (Blueprint $table): void {
                $table->bigIncrements('remote_event_id');
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('device_serial', 120);
                $table->string('local_event_id', 120);
                $table->unsignedBigInteger('collaborator_id');
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->dateTime('event_time_utc');
                $table->dateTime('event_time_local');
                $table->string('tz', 120);
                $table->string('type', 20);
                $table->string('source', 80)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->unique(['device_serial', 'local_event_id'], 'att_raw_device_local_unique');
            });
            $this->createdAttendancesRawTable = true;
        }
    }

    private function cleanData(): void
    {
        foreach (['attendances_raw', 'employees', 'locations', 'device_nonces', 'devices', 'clocks'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function deleteReportFile(): void
    {
        $reportPath = storage_path('app/onprem_diagnostics.json');
        if (File::exists($reportPath)) {
            File::delete($reportPath);
        }
    }
}
