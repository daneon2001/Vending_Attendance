<?php

namespace Tests\Feature\Api;

use App\Models\Clock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClockCatalogTest extends TestCase
{
    private const CLOCK_CATALOG_URI = '/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        if (! Schema::hasTable('clocks')) {
            Schema::create('clocks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('clock_name');
                $table->string('serial_number')->nullable();
                $table->string('firmware_version')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('type_inout', 50)->nullable();
                $table->tinyInteger('status')->default(1);
                $table->unsignedBigInteger('location_id')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->string('last_status_message')->nullable();
                $table->string('monitoring_status', 20)->default('offline');
                $table->string('program_status', 30)->default('offline');
                $table->timestamps();
            });
        }

        Clock::query()->delete();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('clocks')) {
            Schema::drop('clocks');
        }

        parent::tearDown();
    }

    public function test_clock_catalog_returns_data_key_without_filter(): void
    {
        Clock::create([
            'clock_name' => 'Reloj A',
            'serial_number' => 'SER-A',
            'status' => 1,
        ]);
        Clock::create([
            'clock_name' => 'Reloj B',
            'serial_number' => 'SER-B',
            'status' => 1,
        ]);

        $response = $this->getJson(self::CLOCK_CATALOG_URI);

        $response->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonCount(2, 'data');
    }

    public function test_clock_catalog_can_filter_by_serial_number(): void
    {
        Clock::create([
            'clock_name' => 'Reloj A',
            'serial_number' => 'SER-A',
            'status' => 1,
        ]);
        Clock::create([
            'clock_name' => 'Reloj B',
            'serial_number' => 'SER-B',
            'status' => 1,
        ]);

        $response = $this->getJson(self::CLOCK_CATALOG_URI.'?serial_number=SER-B');

        $response->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'SER-B');
    }

    public function test_clock_catalog_returns_empty_data_when_serial_does_not_exist(): void
    {
        Clock::create([
            'clock_name' => 'Reloj A',
            'serial_number' => 'SER-A',
            'status' => 1,
        ]);

        $response = $this->getJson(self::CLOCK_CATALOG_URI.'?serial_number=SER-NOT-FOUND');

        $response->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonCount(0, 'data');
    }
}
