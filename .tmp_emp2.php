<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$count = Illuminate\Support\Facades\DB::table('employees')->count();
$max = Illuminate\Support\Facades\DB::table('employees')->max('id');
$has90 = Illuminate\Support\Facades\DB::table('employees')->where('id',90)->exists();
echo "count={$count} max_id={$max} has_id_90=".($has90?'yes':'no').PHP_EOL;
