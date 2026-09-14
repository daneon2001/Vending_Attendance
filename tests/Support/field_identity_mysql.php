<?php

// Legacy local-DB harness retired after the config-cache incident.
fwrite(STDERR, "TEST_DATABASE_SAFETY_BLOCKED environment=UNKNOWN driver=UNKNOWN database=UNKNOWN reason=legacy harness disabled; use tests/Support/disposable_mysql.php\n");
exit(1);


// Opt-in, disposable MySQL only. Never migrates the configured application DB.
use App\Enums\Employees\EmployeeSource;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\User;
use App\Services\FieldIdentity\DeviceIdentityService;
use App\Services\FieldIdentity\RegisteredPhoneSource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || DB::connection()->getDriverName() !== 'mysql') {
    exit("BLOCKED: isolated MySQL harness requires local MySQL.\n");
}
config(['logging.default' => 'null', 'cache.default' => 'array', 'session.driver' => 'array']);
Http::preventStrayRequests();
$base = DB::connection()->getConfig();
$safe = static fn ($name) => preg_match('/^field_identity_test_[a-f0-9]{16}$/D', $name) === 1
    && $name !== $base['database'];
$connect = static function ($name) use ($safe, $base): void {
    if (! $safe($name)) {
        throw new LogicException('Unsafe test schema.');
    }
    $config = $base;
    unset($config['url'], $config['name'], $config['read'], $config['write']);
    $config['database'] = $name;
    foreach (array_keys(config('database.connections')) as $connection) {
        DB::purge($connection);
        config(['database.connections.'.$connection => $config]);
    }
    config(['database.connections.identity_isolated' => $config]);
    DB::setDefaultConnection('identity_isolated');
    Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
    if (DB::selectOne('SELECT DATABASE() AS name')->name !== $name) {
        throw new LogicException('Isolation mismatch.');
    }
    app()->instance(RegisteredPhoneSource::class, new class extends RegisteredPhoneSource
    {
        public function forEmployee(Employee $employee): ?string
        {
            return '+525500000001';
        }
    });
};
if (($argv[1] ?? '') === 'worker') {
    try {
        [, , $name, $marker, $encoded] = $argv;
        $connect($name);
        if (! hash_equals($marker, DB::table('identity_test_guard')->value('marker'))) {
            throw new LogicException('Not owned.');
        }
        $input = json_decode(base64_decode($encoded), true, 32, JSON_THROW_ON_ERROR);
        auth()->login(User::firstOrFail());
        echo "READY\n";
        flush();
        $result = app(DeviceIdentityService::class)->register($input);
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Throwable $e) {
        echo 'WORKER_FAIL:'.$e::class;
        exit(1);
    }
}
$name = 'field_identity_test_'.bin2hex(random_bytes(8));
$marker = bin2hex(random_bytes(16));
$created = false;
$admin = DB::connection()->getPdo();
$workers = [];
$failed = false;
$check = static function (bool $ok, string $label): void {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    echo $label.": PASS\n";
};
try {
    if (! $safe($name)) {
        throw new LogicException('Unsafe schema.');
    }
    $admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $connect($name);
    DB::statement('CREATE TABLE identity_test_guard (marker VARCHAR(64) NOT NULL)');
    DB::table('identity_test_guard')->insert(['marker' => $marker]);
    $check(Artisan::call('migrate', ['--database' => 'identity_isolated', '--force' => true]) === 0, 'ISOLATED_MYSQL_MIGRATION');
    $employee = Employee::create(['fortia_employee_id' => 999990001, 'employee_number' => 'ISOLATED-1',
        'full_name' => 'Synthetic Identity', 'source' => EmployeeSource::FORTIA,
        'source_external_id' => 'ISOLATED-1', 'status' => 'A']);
    $user = User::factory()->create(['estatus' => true]);
    $user->employee()->associate($employee);
    $user->save();
    auth()->login($user);
    $service = app(DeviceIdentityService::class);
    $otp = $service->sendOtp();
    $service->verifyOtp($otp['otp_uuid'], $otp['local_code']);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (! $key) {
        throw new RuntimeException('Test OpenSSL configuration unavailable.');
    }
    $input = ['device_uuid' => (string) Str::uuid(), 'operation_uuid' => (string) Str::uuid(),
        'otp_uuid' => $otp['otp_uuid'], 'public_key' => openssl_pkey_get_details($key)['key'],
        'platform' => 'android', 'platform_version' => '15', 'app_version' => 'test',
        'hardware_model' => 'Synthetic', 'replaces_uuid' => null];
    // Force both independent processes to reach the same row lock before release.
    DB::beginTransaction();
    User::whereKey($user->id)->lockForUpdate()->first();
    foreach (range(1, 2) as $unused) {
        $worker = new Process([PHP_BINARY, __FILE__, 'worker', $name, $marker,
            base64_encode(json_encode($input, JSON_THROW_ON_ERROR))], dirname(__DIR__, 2), timeout: 30);
        $worker->start();
        $workers[] = $worker;
    }
    $deadline = microtime(true) + 20;
    while (count(array_filter($workers, fn ($worker) => str_contains($worker->getOutput(), 'READY'))) < 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timeout.');
        }
        usleep(10000);
    }
    DB::commit();
    foreach ($workers as $worker) {
        $worker->wait();
        $check($worker->isSuccessful() && str_contains($worker->getOutput(), 'PENDING'), 'CONCURRENT_REGISTRATION_RETRY');
    }
    $check(EmployeeDevice::count() === 1, 'DURABLE_IDEMPOTENCY');
    $challenge = $service->challenge($input['device_uuid']);
    openssl_sign($challenge['message'], $signature, $key, OPENSSL_ALGO_SHA256);
    $check($service->prove($challenge['challenge_uuid'], base64_encode($signature))['status'] === 'ACTIVE', 'MYSQL_SIGNATURE_ACTIVATION');
    $device = EmployeeDevice::firstOrFail();
    $duplicate = $device->replicate();
    $duplicate->forceFill(['uuid' => (string) Str::uuid(), 'operation_uuid' => (string) Str::uuid(),
        'key_fingerprint' => hash('sha256', random_bytes(32))]);
    try {
        $duplicate->save();
        throw new RuntimeException('Missing active employee unique constraint.');
    } catch (Illuminate\Database\QueryException $e) {
        $check($e->errorInfo[0] === '23000', 'ACTIVE_EMPLOYEE_UNIQUE');
    }
    try {
        $user->delete();
        throw new RuntimeException('Missing historical owner protection.');
    } catch (Illuminate\Database\QueryException $e) {
        $check($e->errorInfo[0] === '23000', 'HISTORICAL_USER_RESTRICT');
    }
    $service->revoke($input['device_uuid']);
    $check(EmployeeDevice::first()->status === 'REVOKED', 'MYSQL_REVOCATION');
    $check(DB::table('vending_attendance_events')->count() === 0
        && DB::table('attendance_logs')->count() === 0 && DB::table('devices')->count() === 0, 'DOMAIN_ISOLATION');
} catch (Throwable $e) {
    $failed = true;
    // Never dump QueryException SQL/parameters or credentials.
    echo 'MYSQL_GATE_FAILED: '.$e::class.PHP_EOL;
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($workers as $worker) {
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
    }
    if ($created && $safe($name)) {
        $owner = $admin->query('SELECT marker FROM `'.$name.'`.identity_test_guard')->fetchColumn();
        if (hash_equals($marker, (string) $owner)) {
            DB::disconnect('identity_isolated');
            $admin->exec('DROP DATABASE `'.$name.'`');
            echo "OWNED_DISPOSABLE_SCHEMA_REMOVED: PASS\n";
        } else {
            $failed = true;
            echo "DISPOSABLE_SCHEMA_CLEANUP: BLOCKED_OWNERSHIP\n";
        }
    }
}
exit($failed ? 1 : 0);
