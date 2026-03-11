<?php

namespace Database\Seeders;

use App\Models\FortiaMockEmployee;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class FortiaMockEmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $records = [];
        $companies = [
            [
                'company_id' => 10,
                'company_name' => 'Medical Life Norte',
                'locations' => [
                    [101, 'Planta Monterrey'],
                    [102, 'Planta Saltillo'],
                ],
                'departments' => [
                    [1001, 'Operaciones'],
                    [1002, 'Calidad'],
                ],
            ],
            [
                'company_id' => 20,
                'company_name' => 'Medical Life Sur',
                'locations' => [
                    [201, 'Clínica Mérida'],
                    [202, 'Clínica Cancún'],
                    [203, 'Clínica Villahermosa'],
                ],
                'departments' => [
                    [2001, 'Mantenimiento'],
                    [2002, 'Administración'],
                ],
            ],
        ];

        $names = [
            ['Ana', 'García', 'Lopez'],
            ['Carlos', 'Soto', 'Ruiz'],
            ['Beatriz', 'Mora', 'Silva'],
            ['Ricardo', 'Peña', 'Torres'],
            ['Fernanda', 'Rivera', 'Juarez'],
            ['Miguel', 'Flores', null],
            ['Paola', 'Mendez', 'Cruz'],
            ['Roberto', 'Hernandez', 'Romero'],
            ['Daniela', 'Serrano', 'Ochoa'],
            ['Luis', 'Gomez', 'Acosta'],
            ['Andrea', 'Salas', 'Vega'],
            ['Ernesto', 'Colon', 'Baeza'],
            ['Mariana', 'Padilla', 'Quintana'],
            ['Omar', 'Delgado', 'Reyes'],
            ['Patricia', 'Hidalgo', null],
            ['Javier', 'Camarillo', 'Sosa'],
            ['Isabel', 'Navarro', 'Ugalde'],
            ['Victor', 'Cuevas', 'Salazar'],
            ['Cecilia', 'Bolaños', 'Mejía'],
            ['Hugo', 'Cortés', 'Beltrán'],
        ];

        $statusPool = ['A', 'A', 'A', 'B']; // bias toward active
        $now = Carbon::now();

        foreach ($names as $idx => [$name, $last, $second]) {
            $company = $companies[$idx % count($companies)];
            $location = $company['locations'][$idx % count($company['locations'])];
            $department = $company['departments'][$idx % count($company['departments'])];

            $records[] = [
                'company_id' => $company['company_id'],
                'company_name' => $company['company_name'],
                'employee_id' => 5000 + $idx,
                'name' => $name,
                'last_name' => $last,
                'second_last_name' => $second,
                'status' => $statusPool[array_rand($statusPool)],
                'base_location_id' => $location[0],
                'base_location_name' => $location[1],
                'department_id' => $department[0],
                'department_name' => $department[1],
                'rfc' => 'RFC' . str_pad((string) $idx, 5, '0', STR_PAD_LEFT),
                'imss_number' => 'IMSS' . str_pad((string) $idx, 5, '0', STR_PAD_LEFT),
                'curp' => 'CURP' . str_pad((string) $idx, 5, '0', STR_PAD_LEFT),
                'email_company' => strtolower($name) . '.' . strtolower($last) . '@medical.test',
                'created_at' => $now->copy()->subDays(rand(5, 20)),
                'updated_at' => $now->copy()->subDays(rand(0, 10))->subHours(rand(0, 23)),
            ];
        }

        FortiaMockEmployee::on('fortia_mock')->upsert(
            $records,
            ['company_id', 'employee_id'],
            array_keys($records[0])
        );
    }
}
