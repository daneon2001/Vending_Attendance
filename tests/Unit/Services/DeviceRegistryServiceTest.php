<?php

namespace Tests\Unit\Services;

use App\Models\Clock;
use App\Models\Device;
use App\Services\OnPrem\DeviceRegistryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeviceRegistryServiceTest extends TestCase
{
    private bool $createdClocksTable = false;
    private bool $createdDevicesTable = false;

    protected function setUp(): void
    {
        parent::setUp();

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

        parent::tearDown();
    }

    public function test_sync_from_clock_returns_null_when_serial_is_missing(): void
    {
        config(['onprem.default_shared_secret' => '']);

        $clock = $this->insertClock([
            'serial_number' => null,
            'device_serial' => null,
            'serial' => null,
            'device_id' => null,
            'st_Serial' => null,
        ]);

        $service = new DeviceRegistryService();
        $result = $service->syncFromClock($clock);

        $this->assertNull($result);
        $this->assertSame(0, Device::query()->count());
    }

    public function test_sync_from_clock_upserts_when_serial_is_present(): void
    {
        $clock = $this->insertClock([
            'serial_number' => null,
            'location_id' => 12,
            'company_id' => 44,
            'status' => 1,
        ]);

        $service = new DeviceRegistryService();
        $created = $service->syncFromClock(
            clock: $clock,
            deviceSerial: 'SYNC-DEV-001',
            sharedSecret: 'secret-diag-001',
        );

        $this->assertInstanceOf(Device::class, $created);
        $this->assertSame('SYNC-DEV-001', $created->device_serial);
        $this->assertSame(1, Device::query()->count());

        $clock->forceFill([
            'location_id' => 18,
            'status' => 0,
        ])->save();

        $updated = $service->syncFromClock(
            clock: $clock,
            deviceSerial: 'SYNC-DEV-001',
        );

        $this->assertInstanceOf(Device::class, $updated);
        $this->assertSame($created->id, $updated->id);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(18, (int) Device::query()->whereKey($updated->id)->value('unit_id'));
        $this->assertFalse((bool) Device::query()->whereKey($updated->id)->value('is_active'));
    }

    public function test_sync_from_clock_generates_shared_secret_when_missing(): void
    {
        config(['onprem.default_shared_secret' => '']);

        $clock = $this->insertClock([
            'serial_number' => 'SYNC-DEV-002',
            'location_id' => 12,
            'company_id' => 44,
            'status' => 1,
        ]);

        $service = new DeviceRegistryService();
        $created = $service->syncFromClock($clock);

        $this->assertInstanceOf(Device::class, $created);
        $this->assertSame('SYNC-DEV-002', $created->device_serial);
        $this->assertNotSame('', trim((string) $created->shared_secret));
        $this->assertSame(64, strlen((string) $created->shared_secret));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertClock(array $overrides = []): Clock
    {
        $payload = [
            'clock_name' => 'Device Registry Clock',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach ([
            'serial_number',
            'device_serial',
            'serial',
            'device_id',
            'st_Serial',
            'location_id',
            'company_id',
            'last_heartbeat_at',
            'last_status_message',
            'last_seen_ip',
            'monitoring_status',
            'program_status',
        ] as $column) {
            if (array_key_exists($column, $overrides)) {
                $payload[$column] = $overrides[$column];
            }
        }

        return Clock::query()->create($payload);
    }

    private function ensureSchema(): void
    {
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

        foreach ([
            'device_serial' => fn (Blueprint $table) => $table->string('device_serial')->nullable(),
            'serial' => fn (Blueprint $table) => $table->string('serial')->nullable(),
            'device_id' => fn (Blueprint $table) => $table->string('device_id')->nullable(),
            'st_Serial' => fn (Blueprint $table) => $table->string('st_Serial')->nullable(),
        ] as $column => $callback) {
            if (! Schema::hasColumn('clocks', $column)) {
                Schema::table('clocks', $callback);
            }
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
    }

    private function cleanData(): void
    {
        if (Schema::hasTable('devices')) {
            DB::table('devices')->delete();
        }

        if (Schema::hasTable('clocks')) {
            DB::table('clocks')->delete();
        }
    }
}
