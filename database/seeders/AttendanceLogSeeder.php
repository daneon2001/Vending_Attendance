<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AttendanceLogSeeder extends Seeder
{
    public function run(): void
    {
        $employee = Employee::first();

        if (! $employee) {
            return;
        }

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'company_id' => $employee->company_id,
            'location_id' => 1,
            'device_id' => 1,
            'log_date' => Carbon::now()->subHours(3),
            'log_type' => 0,
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'company_id' => $employee->company_id,
            'location_id' => 1,
            'device_id' => 1,
            'log_date' => Carbon::now()->subHours(1),
            'log_type' => 1,
        ]);
    }
}
