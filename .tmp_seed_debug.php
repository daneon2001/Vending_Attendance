<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'companies=' . App\Models\Company::count() . PHP_EOL;
echo 'locations=' . App\Models\Location::count() . PHP_EOL;
echo 'clocks_before=' . App\Models\Clock::count() . PHP_EOL;

$seeder = new Database\Seeders\ClockSeeder();
$seeder->run();

echo 'clocks_after=' . App\Models\Clock::count() . PHP_EOL;
$rows = App\Models\Clock::query()->get(['id','clock_name','serial_number','company_id','location_id'])->toArray();
print_r($rows);
