<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$rows = Illuminate\Support\Facades\DB::table('employees')->select('id','fortia_employee_id','full_name','status')->orderBy('id')->limit(20)->get();
echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
