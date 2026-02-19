<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class AttendanceLogSeeder extends Seeder
{
    public function run(): void
    {
        $employee = Employee::first();
        $clock = Clock::first();

        if (! $employee || ! $clock) {
            return;
        }

        $basePayload = [
            'employee_id' => $employee->id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'company_id' => $employee->company_id,
            'location_id' => $clock->location_id,
            'device_id' => $clock->id,
        ];

        $firstLogId = (AttendanceLog::max('log_id') ?? 0) + 1;
        $secondLogId = $firstLogId + 1;

        $first = array_merge($basePayload, [
            'log_id' => $firstLogId,
            'log_date' => Carbon::now()->subHours(3),
            'log_type' => 1,
        ]);

        $second = array_merge($basePayload, [
            'log_id' => $secondLogId,
            'log_date' => Carbon::now()->subHours(1),
            'log_type' => 2,
        ]);

        if (Schema::hasColumn('attendance_logs', 'source')) {
            $first['source'] = 'seed';
            $second['source'] = 'seed';
        }

        if (Schema::hasColumn('attendance_logs', 'attendance_status')) {
            $first['attendance_status'] = 'valida';
            $second['attendance_status'] = 'valida';
        }

        AttendanceLog::create($first);

        AttendanceLog::create($second);

        /*
         * Mantener compatibilidad con instalaciones viejas: si existe local_id, generar uno
         * para evitar colisiones en pruebas repetidas.
         */
        if (Schema::hasColumn('attendance_logs', 'local_id')) {
            AttendanceLog::query()
                ->where('log_id', $firstLogId)
                ->update(['local_id' => 'seed-'.$firstLogId.'-'.$clock->id]);

            AttendanceLog::query()
                ->where('log_id', $secondLogId)
                ->update(['local_id' => 'seed-'.$secondLogId.'-'.$clock->id]);
        }
    }
}
