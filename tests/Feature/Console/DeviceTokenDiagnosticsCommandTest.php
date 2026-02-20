<?php

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeviceTokenDiagnosticsCommandTest extends TestCase
{
    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdClockLogsTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdDevicesTable = false;
    private bool $createdAttendanceLogsTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdEmployeeTemplateDeletionsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['device.static_token' => 'DIAG_DEVICE_STATIC_TOKEN_123']);

        $this->ensureSchema();
        $this->cleanData();
        $this->deleteReportFile();
    }

    protected function tearDown(): void
    {
        $this->deleteReportFile();

        if ($this->createdEmployeeTemplateDeletionsTable && Schema::hasTable('employee_template_deletions')) {
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
        if ($this->createdDevicesTable && Schema::hasTable('devices')) {
            Schema::drop('devices');
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

    public function test_device_token_diagnostics_command_passes_and_generates_json_report(): void
    {
        $this->artisan('fortia:diagnose-device-token')
            ->assertExitCode(0);

        $reportPath = storage_path('app/device_token_diagnostics.json');
        $this->assertFileExists($reportPath);

        $report = json_decode((string) File::get($reportPath), true);
        $this->assertIsArray($report);
        $this->assertSame(0, (int) ($report['summary']['failed'] ?? -1));

        $checks = collect($report['checks'] ?? [])->keyBy('name');
        foreach ([
            'config.device_static_token.present',
            'route.exists.attendance_from_device',
            'route.exists.clock_heartbeat',
            'route.exists.employees_catalog',
            'route.exists.employees_templates',
            'middleware.attendance_from_device.has_device_token',
            'middleware.clock_heartbeat.has_device_token',
            'middleware.employees_catalog.has_device_token',
            'middleware.employees_templates.has_device_token',
            'middleware.attendance_from_device.no_sanctum',
            'middleware.clock_heartbeat.no_sanctum',
            'middleware.employees_catalog.no_sanctum',
            'middleware.employees_templates.no_sanctum',
            'e2e.attendance_from_device.201_or_200',
            'e2e.clock_heartbeat.200',
            'e2e.employees_catalog.200',
            'e2e.employees_templates.200',
        ] as $checkName) {
            $this->assertSame('PASS', $checks->get($checkName)['status'] ?? null, "Check {$checkName} did not PASS.");
        }
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('name')->nullable();
                $table->string('code', 30)->nullable();
                $table->string('timezone', 60)->nullable();
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
                $table->unsignedBigInteger('fortia_employee_id')->nullable()->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('base_location_name')->nullable();
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
                $table->unsignedSmallInteger('log_type')->default(1);
                $table->string('source', 50)->nullable();
                $table->string('attendance_status', 30)->nullable();
                $table->dateTime('ingested_at_utc')->nullable();
                $table->string('ingest_ip', 45)->nullable();
                $table->string('device_serial', 120)->nullable();
                $table->string('auth_key_id', 120)->nullable();
                $table->string('request_id', 64)->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamps();
                $table->unique(['local_id', 'device_id'], 'attendance_logs_local_device_unique');
            });
            $this->createdAttendanceLogsTable = true;
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

        if (! Schema::hasTable('employee_fingerprints')) {
            Schema::create('employee_fingerprints', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('vendor_template_id')->nullable();
                $table->longText('template_b64')->nullable();
                $table->string('template_format', 40)->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('status', 30)->default('enrolled');
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
            $this->createdEmployeeTemplateDeletionsTable = true;
        }
    }

    private function cleanData(): void
    {
        foreach ([
            'employee_template_deletions',
            'employee_fingerprints',
            'attendance_logs',
            'devices',
            'employees',
            'clock_logs',
            'clocks',
            'locations',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function deleteReportFile(): void
    {
        $reportPath = storage_path('app/device_token_diagnostics.json');
        if (File::exists($reportPath)) {
            File::delete($reportPath);
        }
    }
}
