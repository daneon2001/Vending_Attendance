<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeEmploymentDatesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_dates_from_metadata_without_overwriting_existing_values(): void
    {
        $employee = Employee::query()->create([
            'fortia_employee_id' => 99101,
            'full_name' => 'Empleado Historico',
            'status' => 'B',
            'termination_date' => '2026-07-01',
        ]);

        DB::table('employee_import_metadata')->insert([
            'employee_id' => $employee->id,
            'source' => 'employees_excel',
            'payload' => json_encode([
                'fecha_ing' => '2024-01-15',
                'fecha_baja' => '2026-06-30',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_17_120000_add_employment_dates_to_employees_table.php');
        $migration->up();

        $employee->refresh();

        $this->assertSame('2024-01-15', $employee->hire_date?->toDateString());
        $this->assertSame('2026-07-01', $employee->termination_date?->toDateString());
    }
}
