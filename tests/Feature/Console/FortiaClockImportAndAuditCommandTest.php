<?php

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FortiaClockImportAndAuditCommandTest extends TestCase
{
    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();
        $this->truncateTables();
        $this->csvPath = storage_path('framework/testing/relojes_fuente_test.csv');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->csvPath)) {
            File::delete($this->csvPath);
        }

        $this->truncateTables();
        $this->dropSchema();

        parent::tearDown();
    }

    public function test_import_clocks_preserves_existing_location_and_is_idempotent(): void
    {
        DB::table('companies')->insert([
            'id' => 1,
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('locations')->insert([
            'id' => 10,
            'company_id' => 1,
            'name' => 'Unidad Centro',
            'code' => 'UC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clocks')->insert([
            'id' => 100,
            'company_id' => 1,
            'clock_name' => 'Reloj Existente',
            'serial_number' => '2',
            'status' => 1,
            'monitoring_status' => 'offline',
            'program_status' => 'offline',
            'location_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        File::ensureDirectoryExists(dirname($this->csvPath));
        File::put($this->csvPath, implode("\n", [
            'Empresa,Clave Serial,Nombre',
            'Medical Life,2,Reloj Acceso',
            'Medical Life,3,Reloj Comedor',
            'Empresa Inexistente,4,Reloj Skip',
            'Medical Life,,Reloj Invalido',
        ]));

        $this->artisan('fortia:import-clocks', [
            '--source' => $this->csvPath,
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('clocks')->count());

        $clockExisting = DB::table('clocks')->where('serial_number', '2')->first();
        $clockNew = DB::table('clocks')->where('serial_number', '3')->first();

        $this->assertNotNull($clockExisting);
        $this->assertSame('Reloj Acceso', $clockExisting->clock_name);
        $this->assertSame(10, (int) $clockExisting->location_id, 'No debe modificar location_id existente');

        $this->assertNotNull($clockNew);
        $this->assertSame('Reloj Comedor', $clockNew->clock_name);
        $this->assertNull($clockNew->location_id, 'No debe autoasignar unidad en fase inicial');

        $this->assertFalse(DB::table('clocks')->where('serial_number', '4')->exists());

        $this->artisan('fortia:import-clocks', [
            '--source' => $this->csvPath,
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('clocks')->count());
    }

    public function test_audit_clocks_reports_expected_metrics(): void
    {
        DB::table('companies')->insert([
            'id' => 1,
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clocks')->insert([
            [
                'company_id' => 999,
                'clock_name' => '',
                'serial_number' => 'S1',
                'status' => 0,
                'monitoring_status' => 'offline',
                'program_status' => 'offline',
                'location_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => 1,
                'clock_name' => 'Reloj 2',
                'serial_number' => 'S1',
                'status' => 1,
                'monitoring_status' => 'online',
                'program_status' => 'online',
                'location_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Artisan::call('fortia:audit-clocks', ['--json' => true, '--sample' => 5]);
        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);
        $this->assertSame(2, (int) ($report['counts']['total_clocks'] ?? 0));
        $this->assertSame(2, (int) ($report['counts']['without_location'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['company_not_found'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['duplicates_by_serial'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['without_name'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['inactive'] ?? 0));
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('code', 20)->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('name');
                $table->string('code', 30)->nullable();
                $table->timestamps();
            });
        }

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
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->string('last_status_message')->nullable();
                $table->string('last_seen_ip', 45)->nullable();
                $table->string('monitoring_status', 20)->default('offline');
                $table->string('program_status', 30)->default('offline');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->timestamps();
            });
        }
    }

    private function truncateTables(): void
    {
        foreach (['clocks', 'locations', 'companies'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function dropSchema(): void
    {
        foreach (['clocks', 'locations', 'companies'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
}
