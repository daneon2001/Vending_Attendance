<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'companies=' . App\Models\Company::count() . PHP_EOL;
echo 'locations=' . App\Models\Location::count() . PHP_EOL;
echo 'clocks=' . App\Models\Clock::count() . PHP_EOL;
echo 'employees=' . App\Models\Employee::count() . PHP_EOL;
echo 'users=' . App\Models\User::count() . PHP_EOL;
echo 'devices=' . App\Models\Device::count() . PHP_EOL;

$admin = App\Models\User::where('email','admin@gmail.com')->first();
echo 'admin_exists=' . ($admin ? 'yes' : 'no') . PHP_EOL;

$devices = App\Models\Device::query()->orderBy('id')->get(['id','device_serial','clock_id','unit_id','company_id','is_active']);
foreach ($devices as $d) {
    echo sprintf('device[%d]=%s clock=%s unit=%s company=%s active=%s', $d->id, $d->device_serial, $d->clock_id, $d->unit_id, $d->company_id, $d->is_active ? '1' : '0') . PHP_EOL;
}
