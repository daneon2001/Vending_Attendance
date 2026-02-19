<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = App\Models\AttendanceLog::query()
    ->where('source','api')
    ->orderByDesc('log_date')
    ->limit(5)
    ->get(['id','log_id','local_id','employee_id','device_id','location_id','log_date','source','attendance_status']);
foreach($rows as $r){
  echo "id={$r->id} log_id={$r->log_id} local_id={$r->local_id} emp={$r->employee_id} dev={$r->device_id} loc={$r->location_id} date={$r->log_date} source={$r->source} status={$r->attendance_status}\n";
}
