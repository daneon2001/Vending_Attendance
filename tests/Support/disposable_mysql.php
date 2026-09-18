<?php

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$lease = null;
try {
    $lease = new Tests\Support\DisposableMysql(config('database.connections.mysql'));
    config(['database.connections.disposable' => $lease->config]);
    Illuminate\Support\Facades\DB::setDefaultConnection('disposable');
    config(['integrations.fortia_mock_migrations' => false]);
    Illuminate\Support\Facades\DB::listen(function ($query) {
        if ($query->connectionName !== 'disposable') {
            throw new LogicException('External connection used by beta migrations');
        }
    });
    if (Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS n')->n !== $lease->name) {
        throw new LogicException('TEST_DATABASE_SAFETY_BLOCKED reason=target mismatch');
    }
    if (Illuminate\Support\Facades\Artisan::call('migrate', ['--database' => 'disposable', '--force' => true]) !== 0
        || Illuminate\Support\Facades\DB::table('migrations')->count() !== 105) {
        throw new RuntimeException('Disposable migrations failed');
    }
    echo 'MYSQL DISPOSABLE: PASS database='.$lease->name.PHP_EOL;
} catch (Throwable $e) {
    // PDO errors can contain usernames. Print only class and a stable status.
    fwrite(STDERR, 'MYSQL DISPOSABLE: FAIL exception='.$e::class.PHP_EOL);
    $failed = true;
} finally {
    Illuminate\Support\Facades\DB::disconnect('disposable');
    if ($lease !== null) {
        $lease->close();
    }
}
echo 'MYSQL CLEANUP: PASS; TEST DATABASES CREATED BY THIS RUN REMAINING: 0'.PHP_EOL;
exit(isset($failed) ? 1 : 0);
