<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        Employee::factory()->createMany([
            [
                'fortia_employee_id' => 1001,
                'company_id' => 1,
                'company_name' => 'Medical Life',
                'base_location_name' => 'Matriz',
                'name' => 'Ana',
                'last_name' => 'García',
                'second_last_name' => 'Lopez',
                'full_name' => 'Ana García Lopez',
                'status' => 'A',
                'rfc' => 'GAL920101XX1',
            ],
            [
                'fortia_employee_id' => 1002,
                'company_id' => 1,
                'company_name' => 'Medical Life',
                'base_location_name' => 'Norte',
                'name' => 'Carlos',
                'last_name' => 'Soto',
                'second_last_name' => 'Ruiz',
                'full_name' => 'Carlos Soto Ruiz',
                'status' => 'A',
                'rfc' => 'SOR930202XX2',
            ],
            [
                'fortia_employee_id' => 1003,
                'company_id' => 1,
                'company_name' => 'Medical Life',
                'base_location_name' => 'Sur',
                'name' => 'Beatriz',
                'last_name' => 'Mora',
                'second_last_name' => 'Silva',
                'full_name' => 'Beatriz Mora Silva',
                'status' => 'B',
                'rfc' => 'MOS910303XX3',
            ],
        ]);
    }
}
