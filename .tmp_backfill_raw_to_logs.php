<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AttendanceRaw;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Device;
use Illuminate\Support\Facades\Schema;

if (!Schema::hasTable('attendance_logs')) {
    echo "attendance_logs table missing\n";
    exit(0);
}

$created = 0;
$skipped = 0;

AttendanceRaw::query()->orderBy('remote_event_id')->chunk(100, function ($rows) use (&$created, &$skipped) {
    foreach ($rows as $raw) {
        $device = Device::query()->find($raw->device_id);
        $deviceId = $raw->clock_id ?? $device?->clock_id;

        $existing = null;
        if (Schema::hasColumn('attendance_logs', 'local_id')) {
            $existing = AttendanceRecord::query()
                ->where('local_id', $raw->local_event_id)
                ->where('device_id', $deviceId)
                ->first();
        }

        if ($existing) {
            $skipped++;
            continue;
        }

        $employee = Employee::query()
            ->where('id', (int)$raw->collaborator_id)
            ->orWhere('fortia_employee_id', (int)$raw->collaborator_id)
            ->first();

        if (!$employee) {
            $skipped++;
            continue;
        }

        $payload = [
            'log_id' => ((int)(AttendanceRecord::query()->max('log_id') ?? 0)) + 1,
            'employee_id' => $employee->id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'company_id' => $raw->company_id ?? $employee->company_id,
            'location_id' => $raw->unit_id ?? $employee->base_location_id,
            'device_id' => $deviceId,
            'log_date' => $raw->event_time_utc,
            'log_type' => strtoupper((string)$raw->type) === 'IN' ? 1 : (strtoupper((string)$raw->type) === 'OUT' ? 2 : 0),
        ];

        if (Schema::hasColumn('attendance_logs', 'local_id')) {
            $payload['local_id'] = $raw->local_event_id;
        }
        if (Schema::hasColumn('attendance_logs', 'source')) {
            $payload['source'] = 'api';
        }
        if (Schema::hasColumn('attendance_logs', 'attendance_status')) {
            $payload['attendance_status'] = 'valida';
        }
        if (Schema::hasColumn('attendance_logs', 'status')) {
            $payload['status'] = 1;
        }
        if (Schema::hasColumn('attendance_logs', 'raw_payload')) {
            $payload['raw_payload'] = [
                'provider' => 'onprem_backfill',
                'raw_remote_id' => $raw->remote_event_id,
                'timezone' => $raw->tz,
                'event_time_local' => optional($raw->event_time_local)->toDateTimeString(),
                'source' => $raw->source,
                'meta' => $raw->meta,
            ];
        }

        AttendanceRecord::query()->create($payload);
        $created++;
    }
});

echo "created=$created skipped=$skipped\n";
