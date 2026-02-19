<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$user = App\Models\User::where('email','admin@gmail.com')->first();
if (!$user) { echo "admin_not_found\n"; exit; }
echo 'admin_password_ok=' . (Illuminate\Support\Facades\Hash::check('password', $user->password) ? 'yes' : 'no') . PHP_EOL;
