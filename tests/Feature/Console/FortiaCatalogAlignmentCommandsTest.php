<?php

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FortiaCatalogAlignmentCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();
        $this->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->truncateTables();
        $this->dropSchema();

        parent::tearDown();
    }

    public function test_sync_operational_catalogs_creates_catalogs_from_employees_without_duplicates(): void
    {
        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 1001,
                'company_id' => 10,
                'company_name' => 'Compania Uno',
                'base_location_id' => 501,
                'base_location_name' => 'Unidad Norte',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fortia_employee_id' => 1002,
                'company_id' => 10,
                'company_name' => 'Compania Uno',
                'base_location_id' => 501,
                'base_location_name' => 'Unidad Norte',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fortia_employee_id' => 1003,
                'company_id' => 20,
                'company_name' => 'Compania Dos',
                'base_location_id' => 777,
                'base_location_name' => 'Unidad Sur',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('fortia:sync-operational-catalogs')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('companies')->count());
        $this->assertSame(2, DB::table('locations')->count());

        $companyOne = DB::table('companies')->where('fortia_company_id', 10)->first();
        $locationOne = DB::table('locations')->where('fortia_location_id', 501)->first();

        $this->assertNotNull($companyOne);
        $this->assertSame('Compania Uno', $companyOne->name);
        $this->assertNotNull($locationOne);
        $this->assertSame('Unidad Norte', $locationOne->name);

        $this->artisan('fortia:sync-operational-catalogs')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('companies')->count());
        $this->assertSame(2, DB::table('locations')->count());

        $duplicateCompanies = DB::table('companies')
            ->selectRaw('fortia_company_id, COUNT(*) as total')
            ->whereNotNull('fortia_company_id')
            ->groupBy('fortia_company_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $duplicateLocations = DB::table('locations')
            ->selectRaw('fortia_location_id, COUNT(*) as total')
            ->whereNotNull('fortia_location_id')
            ->groupBy('fortia_location_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $duplicateCompanies);
        $this->assertSame(0, $duplicateLocations);
    }

    public function test_audit_catalog_alignment_reports_inconsistencies(): void
    {
        DB::table('companies')->insert([
            'fortia_company_id' => 10,
            'name' => 'Compania Uno',
            'code' => '10',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('locations')->insert([
            'fortia_location_id' => 501,
            'company_id' => 1,
            'name' => 'Unidad Norte',
            'code' => '501',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            [
                'fortia_employee_id' => 1001,
                'company_id' => 10,
                'company_name' => 'Compania Uno',
                'base_location_id' => 501,
                'base_location_name' => 'Unidad Norte',
                'department_id' => 55,
                'department_name' => 'Ventas',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'fortia_employee_id' => 1002,
                'company_id' => 99,
                'company_name' => 'Compania Faltante',
                'base_location_id' => 999,
                'base_location_name' => 'Unidad Faltante',
                'department_id' => 77,
                'department_name' => 'RH',
                'status' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('razones_sociales')->insert([
            [
                'cla_razon_social' => '999',
                'nom_razon_social' => 'Razon Sin Empresa',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('ubicaciones')->insert([
            [
                'cla_ubicacion' => '777',
                'nom_ubicacion' => 'Ubicacion Huerfana',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('departamentos')->insert([
            [
                'cla_depto' => '55',
                'nom_departamento' => 'Ventas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('employee_details')->insert([
            'employee_id' => 1,
            'razon_social_id' => 1,
            'departamento_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('fortia:audit-catalog-alignment', ['--json' => true, '--sample' => 3]);
        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);
        $this->assertSame(1, (int) ($report['counts']['employees_without_company_match'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['employees_without_location_match'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['employees_without_employee_details'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['employee_details_with_company_code_mismatch'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['razones_sociales_without_operational_company'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['ubicaciones_without_operational_location'] ?? 0));
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_company_id')->nullable()->unique();
                $table->string('name');
                $table->string('code', 20)->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('companies', 'fortia_company_id')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->unsignedBigInteger('fortia_company_id')->nullable()->unique();
            });
        }

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_location_id')->nullable()->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('name');
                $table->string('code', 30)->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('locations', 'fortia_location_id')) {
            Schema::table('locations', function (Blueprint $table): void {
                $table->unsignedBigInteger('fortia_location_id')->nullable()->unique();
            });
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('company_name')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('base_location_name')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->string('department_name')->nullable();
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('second_last_name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('status', 20)->default('A');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('razones_sociales')) {
            Schema::create('razones_sociales', function (Blueprint $table): void {
                $table->id();
                $table->string('cla_razon_social', 50)->unique();
                $table->string('nom_razon_social', 255);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ubicaciones')) {
            Schema::create('ubicaciones', function (Blueprint $table): void {
                $table->id();
                $table->string('cla_ubicacion', 50)->unique();
                $table->string('nom_ubicacion', 255);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('departamentos')) {
            Schema::create('departamentos', function (Blueprint $table): void {
                $table->id();
                $table->string('cla_depto', 50)->unique();
                $table->string('nom_departamento', 255);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_details')) {
            Schema::create('employee_details', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id')->unique();
                $table->unsignedBigInteger('razon_social_id')->nullable();
                $table->unsignedBigInteger('departamento_id')->nullable();
                $table->unsignedBigInteger('ubicacion_id')->nullable();
                $table->timestamps();
            });
        }
    }

    private function truncateTables(): void
    {
        foreach ([
            'employee_details',
            'employees',
            'locations',
            'companies',
            'departamentos',
            'ubicaciones',
            'razones_sociales',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function dropSchema(): void
    {
        foreach ([
            'employee_details',
            'departamentos',
            'ubicaciones',
            'razones_sociales',
            'employees',
            'locations',
            'companies',
        ] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
}
