<?php

// Legacy local-DB harness retired after the config-cache incident.
fwrite(STDERR, "TEST_DATABASE_SAFETY_BLOCKED environment=UNKNOWN driver=UNKNOWN database=UNKNOWN reason=legacy harness disabled; use tests/Support/disposable_mysql.php\n");
exit(1);


// Opt-in schema verification. No writes to the configured application database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$created = false;
$name = 'field_e_test_'.bin2hex(random_bytes(8));
$failed = false;
try {
    $base = DB::connection()->getConfig();
    if (! $app->environment('local') || $base['driver'] !== 'mysql' || $base['database'] !== 'vending_attendance_dev'
        || ! preg_match('/^field_e_test_[a-f0-9]{16}$/', $name)) {
        throw new LogicException('Unsafe test target.');
    }
    $admin = DB::connection()->getPdo();
    $parents = ['users', 'employees', 'employee_devices', 'vending_support_activities'];
    foreach ($parents as $table) {
        $type = DB::selectOne('SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$base['database'], $table, 'id']);
        if (strtolower($type->type ?? '') !== 'bigint unsigned') {
            throw new LogicException('Parent FK type mismatch.');
        }
    }
    $admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    unset($base['url'], $base['read'], $base['write'], $base['name']);
    $base['database'] = $name;
    config(['database.connections.field_e_isolated' => $base, 'logging.default' => 'null']);
    DB::setDefaultConnection('field_e_isolated');
    Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
    if (DB::selectOne('SELECT DATABASE() AS name')->name !== $name) {
        throw new LogicException('Isolation failed.');
    }
    foreach ($parents as $table) {
        Schema::create($table, fn (Blueprint $schema) => $schema->id());
    }
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_10_190000_create_support_activity_contributions.php';
    $migration->up();
    $constraints = DB::select('SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=?', [$name]);
    if (count($constraints) !== 8 || ! collect($constraints)->every(fn ($row) => $row->DELETE_RULE === 'RESTRICT')) {
        throw new LogicException('FK mismatch.');
    }
    foreach (['support_activity_notes', 'support_activity_evidence'] as $table) {
        $indexes = collect(DB::select('SHOW INDEX FROM '.$table))->groupBy('Key_name');
        if (! $indexes->contains(fn ($rows) => $rows->pluck('Column_name')->all() === ['support_activity_id', 'id'])
            || ! $indexes->contains(fn ($rows) => $rows->first()->Non_unique === 0 && $rows->pluck('Column_name')->all() === ['uuid'])) {
            throw new LogicException('Index mismatch.');
        }
    }
    $migration->down();
    if (Schema::hasTable('support_activity_notes') || Schema::hasTable('support_activity_evidence')) {
        throw new LogicException('Empty rollback failed.');
    }
    $migration->up();
    foreach ($parents as $table) {
        DB::table($table)->insert(['id' => 1]);
    }
    $note = ['uuid' => (string) Illuminate\Support\Str::uuid(), 'support_activity_id' => 1, 'employee_id' => 1,
        'field_mobile_device_id' => 1, 'user_id' => 1, 'body' => 'Synthetic only', 'captured_at' => '2026-09-10 18:00:00', 'created_at' => '2026-09-10 18:01:00', 'updated_at' => '2026-09-10 18:01:00'];
    DB::table('support_activity_notes')->insert($note);
    foreach ([fn () => DB::table('support_activity_notes')->insert($note),
        fn () => DB::table('users')->where('id', 1)->delete(),
        fn () => DB::table('support_activity_notes')->insert(array_replace($note, ['uuid' => (string) Illuminate\Support\Str::uuid(), 'employee_id' => 99]))] as $invalid) {
        try {
            $invalid();
            throw new LogicException('Constraint not enforced.');
        } catch (Illuminate\Database\QueryException $error) {
            if ($error->errorInfo[0] !== '23000') {
                throw $error;
            }
        }
    }
    try {
        $migration->down();
        throw new LogicException('History rollback accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Cannot remove operational contribution history.') {
            throw $error;
        }
    }
    echo "MYSQL_SCHEMA: PASS\nFK_RESTRICT: PASS (8)\nINDEXES: PASS\nUNIQUE_UUID: PASS\nEMPTY_ROLLBACK: PASS\nHISTORY_ROLLBACK_GUARD: PASS\nREAL_DB_WRITES: NONE\n";
} catch (Throwable $error) {
    $failed = true;
    echo 'MYSQL_SCHEMA: FAIL '.$error::class.PHP_EOL;
} finally {
    if ($created) {
        if (! preg_match('/^field_e_test_[a-f0-9]{16}$/', $name)) {
            throw new LogicException('Unsafe cleanup.');
        }
        DB::disconnect('field_e_isolated');
        $admin->exec('DROP DATABASE `'.$name.'`');
        echo 'ISOLATED_SCHEMA_REMOVED: '.$name.PHP_EOL;
    }
}
exit($failed ? 1 : 0);
