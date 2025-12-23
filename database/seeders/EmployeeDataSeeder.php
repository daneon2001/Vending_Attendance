<?php

namespace Database\Seeders;

use App\Models\Clock;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Models\EmployeeStatusChange;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class EmployeeDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $employeesData = [
            [
                'fortia_employee_id' => 9101,
                'company_id' => 1,
                'company_name' => 'Medical Life Matriz',
                'base_location_id' => 101,
                'base_location_name' => 'Oficina Reforma',
                'department_id' => 501,
                'department_name' => 'Operaciones',
                'name' => 'Rene',
                'last_name' => 'Delgado',
                'second_last_name' => 'Solis',
                'full_name' => 'Rene Delgado Solis',
                'status' => 'A',
                'rfc' => 'DESR800101XX1',
                'imss_number' => 'IMSS80010101',
                'curp' => 'DESR800101HDFLLN01',
                'email_company' => 'rene.delgado@medical.life',
                'has_fingerprint' => true,
            ],
            [
                'fortia_employee_id' => 9102,
                'company_id' => 1,
                'company_name' => 'Medical Life Matriz',
                'base_location_id' => 102,
                'base_location_name' => 'Centro Logistico',
                'department_id' => 502,
                'department_name' => 'Calidad',
                'name' => 'Mariela',
                'last_name' => 'Gomez',
                'second_last_name' => 'Padilla',
                'full_name' => 'Mariela Gomez Padilla',
                'status' => 'A',
                'rfc' => 'GOPM820202XX2',
                'imss_number' => 'IMSS82020202',
                'curp' => 'GOPM820202MDFPDL02',
                'email_company' => 'mariela.gomez@medical.life',
                'has_fingerprint' => true,
            ],
            [
                'fortia_employee_id' => 9103,
                'company_id' => 2,
                'company_name' => 'Medical Life Planta Norte',
                'base_location_id' => 201,
                'base_location_name' => 'Parque Apodaca',
                'department_id' => 601,
                'department_name' => 'Mantenimiento',
                'name' => 'Julian',
                'last_name' => 'Ramirez',
                'second_last_name' => 'Lopez',
                'full_name' => 'Julian Ramirez Lopez',
                'status' => 'B',
                'rfc' => 'RALJ830303XX3',
                'imss_number' => 'IMSS83030303',
                'curp' => 'RALJ830303HNLMPN03',
                'email_company' => 'julian.ramirez@medical.life',
                'has_fingerprint' => false,
            ],
        ];

        $employees = [];

        foreach ($employeesData as $data) {
            $employee = Employee::updateOrCreate(
                ['fortia_employee_id' => $data['fortia_employee_id']],
                $data
            );

            $employees[$employee->fortia_employee_id] = $employee;
        }

        $clockIds = Clock::pluck('id')->all();
        if (empty($clockIds)) {
            $clockIds = [null];
        }

        $fingerprintSeeds = [
            [
                'fortia_employee_id' => 9101,
                'status' => 'enrolled',
                'clock_index' => 0,
                'enrolled_at' => $now->copy()->subDays(1),
            ],
            [
                'fortia_employee_id' => 9102,
                'status' => 'pending_delete',
                'clock_index' => 1,
                'enrolled_at' => $now->copy()->subDays(7),
                'deleted_at' => null,
            ],
        ];

        foreach ($fingerprintSeeds as $fingerprintSeed) {
            $employee = $employees[$fingerprintSeed['fortia_employee_id']] ?? null;
            if (! $employee) {
                continue;
            }

            EmployeeFingerprint::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'clock_id' => $clockIds[$fingerprintSeed['clock_index'] % count($clockIds)] ?? null,
                ],
                [
                    'status' => $fingerprintSeed['status'],
                    'enrolled_at' => $fingerprintSeed['enrolled_at'],
                    'deleted_at' => $fingerprintSeed['deleted_at'] ?? null,
                ]
            );

            $employee->refreshFingerprintFlag();
        }

        $statusChanges = [
            [
                'fortia_employee_id' => 9103,
                'old_status' => 'A',
                'new_status' => 'B',
                'changed_at' => $now->copy()->subDays(2),
                'meta' => ['reason' => 'Incidencias repetidas'],
            ],
            [
                'fortia_employee_id' => 9102,
                'old_status' => 'B',
                'new_status' => 'A',
                'changed_at' => $now->copy()->subDay(),
                'meta' => ['reason' => 'Reincorporacion'],
            ],
        ];

        foreach ($statusChanges as $change) {
            $employee = $employees[$change['fortia_employee_id']] ?? null;
            if (! $employee) {
                continue;
            }

            EmployeeStatusChange::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'changed_at' => $change['changed_at'],
                ],
                [
                    'company_id' => $employee->company_id ?? 0,
                    'fortia_employee_id' => $employee->fortia_employee_id,
                    'old_status' => $change['old_status'],
                    'new_status' => $change['new_status'],
                    'source' => 'seeder',
                    'meta' => $change['meta'],
                ]
            );
        }
    }
}
