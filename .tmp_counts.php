<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'companies=' . App\Models\Company::count() . PHP_EOL;
echo 'locations=' . App\Models\Location::count() . PHP_EOL;
echo 'clocks=' . App\Models\Clock::count() . PHP_EOL;
echo 'employees=' . App\Models\Employee::count() . PHP_EOL;
echo 'empleados=' . App\Models\Empleado::count() . PHP_EOL;
echo 'users=' . App\Models\User::count() . PHP_EOL;
echo 'roles=' . App\Models\Role::count() . PHP_EOL;
echo 'permissions=' . App\Models\Permission::count() . PHP_EOL;
echo 'attendance_logs=' . App\Models\AttendanceLog::count() . PHP_EOL;
echo 'attendances_raw=' . App\Models\AttendanceRaw::count() . PHP_EOL;
echo 'devices=' . App\Models\Device::count() . PHP_EOL;
