<?php

namespace Tests\Feature\Api;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class OnPremApiTestCase extends TestCase
{
    private bool $createdCompaniesTable = false;
    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdDevicesTable = false;
    private bool $createdDeviceNoncesTable = false;
    private bool $createdAttendancesRawTable = false;
    private bool $createdAttendanceLogsTable = false;
    private bool $createdAuditLogsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'onprem.hmac_tolerance_seconds' => 300,
            'onprem.nonce_ttl_seconds' => 600,
            'onprem.max_batch_size' => 500,
            'onprem.next_heartbeat_seconds' => 15,
        ]);

        Cache::flush();
        $this->ensureTables();
        $this->clearTables();
    }

    protected function tearDown(): void
    {
        if ($this->createdAuditLogsTable && Schema::hasTable('audit_logs')) {
            Schema::drop('audit_logs');
        }

        Cache::flush();
        if ($this->createdAttendancesRawTable && Schema::hasTable('attendances_raw')) {
            Schema::drop('attendances_raw');
        }
        if ($this->createdAttendanceLogsTable && Schema::hasTable('attendance_logs')) {
            Schema::drop('attendance_logs');
        }
        if ($this->createdDeviceNoncesTable && Schema::hasTable('device_nonces')) {
            Schema::drop('device_nonces');
        }
        if ($this->createdDevicesTable && Schema::hasTable('devices')) {
            Schema::drop('devices');
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
        if ($this->createdCompaniesTable && Schema::hasTable('companies')) {
            Schema::drop('companies');
        }

        parent::tearDown();
    }

    protected function seedDeviceFixture(string $deviceSerial, string $secret): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Company Test',
            'code' => 'COMP',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitId = DB::table('locations')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Unit Test',
            'code' => 'UNIT',
            'timezone' => 'America/Mexico_City',
            'address' => 'Test address',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clockId = DB::table('clocks')->insertGetId([
            'company_id' => $companyId,
            'location_id' => $unitId,
            'clock_name' => 'Clock Test',
            'serial_number' => $deviceSerial,
            'ip_address' => '127.0.0.1',
            'type_inout' => 'INOUT',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('devices')->insert([
            'device_serial' => $deviceSerial,
            'clock_id' => $clockId,
            'unit_id' => $unitId,
            'company_id' => $companyId,
            'shared_secret' => $secret,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'device_serial' => $deviceSerial,
            'secret' => $secret,
            'clock_id' => $clockId,
            'unit_id' => $unitId,
            'company_id' => $companyId,
        ];
    }

    protected function seedCollaborator(int $fortiaEmployeeId, int $companyId, int $unitId, array $attributes = []): int
    {
        $payload = array_merge([
            'fortia_employee_id' => $fortiaEmployeeId,
            'company_id' => $companyId,
            'base_location_id' => $unitId,
            'name' => 'Colaborador',
            'full_name' => 'Colaborador Test',
            'status' => 'A',
            'has_fingerprint' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes);

        if (array_key_exists('id', $payload)) {
            DB::table('employees')->insert($payload);

            return (int) $payload['id'];
        }

        return (int) DB::table('employees')->insertGetId($payload);
    }

    protected function signedJsonRequest(
        string $method,
        string $uri,
        array $payload,
        string $deviceSerial,
        string $secret,
        ?int $timestamp = null,
        ?string $nonce = null,
    ) {
        $method = strtoupper($method);
        $timestamp = $timestamp ?? now()->timestamp;
        $nonce = $nonce ?? (string) Str::uuid();
        $body = in_array($method, ['GET', 'HEAD'], true)
            ? ''
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            $this->fail('Unable to encode JSON payload.');
        }

        $signature = $this->buildSignature(
            method: $method,
            path: $uri,
            timestamp: (string) $timestamp,
            nonce: $nonce,
            body: $body,
            secret: $secret,
        );

        return $this->call(
            $method,
            $uri,
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_DEVICE_SERIAL' => $deviceSerial,
                'HTTP_X_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_NONCE' => $nonce,
                'HTTP_X_SIGNATURE' => $signature,
            ],
            $body
        );
    }

    private function buildSignature(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $secret,
    ): string {
        $bodyHash = hash('sha256', $body);
        $canonical = $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash;

        return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
    }

    private function clearTables(): void
    {
        foreach (['device_nonces', 'attendances_raw', 'attendance_logs', 'audit_logs', 'devices', 'employees', 'clocks', 'locations', 'companies'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('code', 20)->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
            $this->createdCompaniesTable = true;
        }

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('name');
                $table->string('code', 30)->nullable();
                $table->string('timezone', 60)->nullable();
                $table->string('address')->nullable();
                $table->tinyInteger('status')->default(1);
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
        } else {
            Schema::table('clocks', function (Blueprint $table): void {
                if (! Schema::hasColumn('clocks', 'monitoring_status')) {
                    $table->string('monitoring_status', 20)->default('offline');
                }
                if (! Schema::hasColumn('clocks', 'program_status')) {
                    $table->string('program_status', 30)->default('offline');
                }
            });
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('status', 20)->default('A');
                $table->boolean('has_fingerprint')->default(false);
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
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
        } else {
            Schema::table('devices', function (Blueprint $table): void {
                if (! Schema::hasColumn('devices', 'last_heartbeat_at')) {
                    $table->dateTime('last_heartbeat_at')->nullable();
                }
                if (! Schema::hasColumn('devices', 'last_status')) {
                    $table->string('last_status', 500)->nullable();
                }
            });
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

        if (! Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('log_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('fortia_employee_id')->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('local_id', 120)->nullable();
                $table->dateTime('log_date');
                $table->unsignedTinyInteger('log_type')->default(0);
                $table->string('source', 40)->nullable();
                $table->string('attendance_status', 30)->nullable();
                $table->unsignedTinyInteger('status')->nullable();
                $table->json('raw_payload')->nullable();
                $table->dateTime('ingested_at_utc')->nullable();
                $table->string('ingest_ip', 45)->nullable();
                $table->string('device_serial', 120)->nullable();
                $table->string('auth_key_id', 120)->nullable();
                $table->string('request_id', 120)->nullable();
                $table->string('integrity_hash', 64)->nullable();
                $table->string('integrity_previous_hash', 64)->nullable();
                $table->unsignedTinyInteger('integrity_hash_version')->nullable();
                $table->dateTime('integrity_verified_at')->nullable();
                $table->timestamps();

                $table->unique(['local_id', 'device_id'], 'attendance_logs_local_device_unique');
            });
            $this->createdAttendanceLogsTable = true;
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_type', 40)->nullable();
                $table->string('actor_identifier')->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_email')->nullable();
                $table->string('event', 150);
                $table->string('action', 80)->nullable();
                $table->string('entity')->nullable();
                $table->string('entity_id')->nullable();
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->string('description')->nullable();
                $table->string('reason')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('request_id')->nullable();
                $table->string('correlation_id')->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->dateTime('occurred_at_utc')->nullable();
                $table->dateTime('occurred_at_local')->nullable();
                $table->string('timezone', 120)->nullable();
                $table->timestamps();
            });
            $this->createdAuditLogsTable = true;
        }
    }
}
