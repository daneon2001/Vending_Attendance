<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$ids=[639,636,637,638,9101,9102,9103,88001];
foreach($ids as $id){
  $e=App\Models\Employee::query()->where('id',$id)->orWhere('fortia_employee_id',$id)->first();
  echo $id.' => '.($e?('emp_id='.$e->id.' fortia='.$e->fortia_employee_id.' name='.$e->full_name):'NO').PHP_EOL;
}
