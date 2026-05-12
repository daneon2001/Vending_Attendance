<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\CheckTokenExpiration;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClockConfigurationResolveTest extends TestCase
{
    private const URI = '/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/resolve-by-serial';

    private bool $createdUsersTable = false;
    private bool $createdCompaniesTable = false;
    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdDevicesTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(CheckTokenExpiration::class);
        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        if ($this->createdDevicesTable && Schema::hasTable('devices')) {
            Schema::drop('devices');
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
        if ($this->createdUsersTable && Schema::hasTable('users')) {
            Schema::drop('users');
        }

        parent::tearDown();
    }

    public function test_resolve_by_serial_returns_runtime_bundle_and_backfills_device_registry(): void
    {
        Config::set('device.static_token', 'static-device-token');
        Config::set('onprem.default_shared_secret', 'default-shared-secret');

        $this->authenticate();

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Medical Life',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = DB::table('locations')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Centro Logistico',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clockId = DB::table('clocks')->insertGetId([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'clock_name' => 'Checador Norte',
            'serial_number' => 'FT-CHK-001',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(self::URI.'?serial_number=FT-CHK-001');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.clock_id', $clockId)
            ->assertJsonPath('data.device_serial', 'FT-CHK-001')
            ->assertJsonPath('data.unit_id', $locationId)
            ->assertJsonPath('data.company_id', $companyId)
            ->assertJsonPath('data.device_token', 'static-device-token')
            ->assertJsonPath('data.device_shared_secret', 'default-shared-secret')
            ->assertJsonPath('data.timezone', 'America/Mexico_City')
            ->assertJsonPath('data.intervals.heartbeat_seconds', (int) config('onprem.next_heartbeat_seconds', 15));

        $this->assertDatabaseHas('devices', [
            'device_serial' => 'FT-CHK-001',
            'clock_id' => $clockId,
            'unit_id' => $locationId,
            'company_id' => $companyId,
        ]);
    }

    public function test_resolve_by_serial_generates_shared_secret_when_default_is_empty(): void
    {
        Config::set('device.static_token', 'static-device-token');
        Config::set('onprem.default_shared_secret', '');

        $this->authenticate();

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Medical Life',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = DB::table('locations')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Centro Logistico',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clocks')->insert([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'clock_name' => 'Checador Norte',
            'serial_number' => 'FT-CHK-002',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(self::URI.'?serial_number=FT-CHK-002');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.device_serial', 'FT-CHK-002');

        $sharedSecret = (string) $response->json('data.device_shared_secret');

        $this->assertNotSame('', trim($sharedSecret));
        $this->assertSame(64, strlen($sharedSecret));
        $this->assertDatabaseHas('devices', [
            'device_serial' => 'FT-CHK-002',
            'shared_secret' => $sharedSecret,
        ]);
    }

    public function test_resolve_by_serial_requires_a_serial_number(): void
    {
        $this->authenticate();

        $response = $this->getJson(self::URI);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'serial_number is required.');
    }

    public function test_resolve_by_serial_returns_conflict_when_serial_is_not_unique(): void
    {
        $this->authenticate();

        DB::table('clocks')->insert([
            [
                'clock_name' => 'Checador A',
                'serial_number' => 'DUP-001',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'clock_name' => 'Checador B',
                'serial_number' => 'DUP-001',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson(self::URI.'?serial_number=DUP-001');

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Multiple clocks share the provided serial number.');
    }

    private function authenticate(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Clock Admin',
            'email' => 'clock-admin@example.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs(User::query()->findOrFail($userId));
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->timestamps();
            });
            $this->createdUsersTable = true;
        }

        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
            $this->createdCompaniesTable = true;
        }

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('name')->nullable();
                $table->string('timezone')->nullable();
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
                $table->string('clock_name')->default('Clock');
                $table->string('serial_number')->nullable();
                $table->integer('status')->default(1);
                $table->string('monitoring_status')->nullable();
                $table->string('program_status')->nullable();
                $table->string('last_status_message')->nullable();
                $table->string('last_seen_ip')->nullable();
                $table->dateTime('last_heartbeat_at')->nullable();
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
                $table->timestamps();
            });
            $this->createdDevicesTable = true;
        }
    }

    private function cleanData(): void
    {
        if (Schema::hasTable('devices')) {
            DB::table('devices')->delete();
        }
        if (Schema::hasTable('clocks')) {
            DB::table('clocks')->delete();
        }
        if (Schema::hasTable('locations')) {
            DB::table('locations')->delete();
        }
        if (Schema::hasTable('companies')) {
            DB::table('companies')->delete();
        }
        if (Schema::hasTable('users')) {
            DB::table('users')->delete();
        }
    }
}
