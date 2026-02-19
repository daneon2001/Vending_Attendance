<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Clocks:\n";
foreach (App\Models\Clock::query()->orderBy('id')->get(['id','clock_name','serial_number','status','location_id']) as $c) {
    echo "- #{$c->id} {$c->clock_name} ({$c->serial_number}) status={$c->status} location_id={$c->location_id}\n";
}

echo "\nLocations:\n";
foreach (App\Models\Location::query()->orderBy('id')->get(['id','code','name','timezone','status']) as $l) {
    echo "- #{$l->id} {$l->code} {$l->name} tz={$l->timezone} status={$l->status}\n";
}

echo "\nEmployees:\n";
foreach (App\Models\Employee::query()->orderBy('id')->get(['id','fortia_employee_id','full_name','status']) as $e) {
    echo "- #{$e->id} fortia={$e->fortia_employee_id} {$e->full_name} status={$e->status}\n";
}
