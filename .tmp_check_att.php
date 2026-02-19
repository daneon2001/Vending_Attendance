<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'attendance_logs='.App\Models\AttendanceLog::count().PHP_EOL;
echo 'attendances_raw='.App\Models\AttendanceRaw::count().PHP_EOL;

$lastRaw = App\Models\AttendanceRaw::query()->latest('remote_event_id')->first();
if ($lastRaw) {
  echo 'last_raw_id='.$lastRaw->remote_event_id.' local_event_id='.$lastRaw->local_event_id.' collaborator='.$lastRaw->collaborator_id.' utc='.$lastRaw->event_time_utc.PHP_EOL;
}

$lastLog = App\Models\AttendanceLog::query()->latest('id')->first();
if ($lastLog) {
  echo 'last_log_id='.$lastLog->id.' log_id='.$lastLog->log_id.' employee_id='.$lastLog->employee_id.' log_date='.$lastLog->log_date.' source='.$lastLog->source.PHP_EOL;
}
