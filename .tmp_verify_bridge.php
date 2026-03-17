<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'attendance_logs='.App\Models\AttendanceLog::count().PHP_EOL;
$row = App\Models\AttendanceLog::query()->where('local_id','8f18b606-351b-488b-9fda-9ccb93dd8e7b')->first();
if ($row) {
  echo 'found_local_id='.$row->local_id.' id='.$row->id.' date='.$row->log_date.' source='.$row->source.' employee_id='.$row->employee_id.PHP_EOL;
}
