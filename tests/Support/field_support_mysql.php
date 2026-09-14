<?php

// Legacy local-DB harness retired after the config-cache incident.
fwrite(STDERR, "TEST_DATABASE_SAFETY_BLOCKED environment=UNKNOWN driver=UNKNOWN database=UNKNOWN reason=legacy harness disabled; use tests/Support/disposable_mysql.php\n");
exit(1);


// Opt-in harness. All writes target a newly CREATED disposable schema only.
// Run: php tests/Support/field_support_mysql.php
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VendingMachine;
use App\Models\VendingSupportActivity;
use App\Services\Support\SupportActivityService;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportTicketService;
use App\Services\Vending\MachineGeofenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['logging.default' => 'null', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
\Illuminate\Support\Facades\Http::preventStrayRequests();

if (! $app->environment('local') || DB::connection()->getDriverName() !== 'mysql') {
    fwrite(STDERR, "BLOCKED:MYSQL_TEST_DATABASE_NOT_SAFE\n");
    exit(2);
}
$base = DB::connection()->getConfig();
$safeName = static fn (string $name): bool => preg_match('/^field_support_136b1_test_[a-f0-9]{16}$/', $name) === 1
    && $name !== $base['database'] && $name !== 'vending_attendance_dev';
$connect = static function (string $name) use ($base, $safeName): void {
    if (! $safeName($name)) {
        throw new LogicException('Unsafe isolated schema name.');
    }
    $isolated = $base;
    unset($isolated['name'], $isolated['url'], $isolated['read'], $isolated['write']);
    $isolated['database'] = $name;
    foreach (array_keys(config('database.connections')) as $connection) {
        DB::purge($connection);
        config(['database.connections.'.$connection => $isolated]);
    }
    config(['database.connections.field_isolated' => $isolated]);
    DB::purge('field_isolated');
    DB::setDefaultConnection('field_isolated');
    \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
    if (DB::selectOne('SELECT DATABASE() AS db')->db !== $name) {
        throw new LogicException('Isolated database identity mismatch.');
    }
};
$check = static function (bool $ok, string $label): void {
    if (! $ok) {
        echo $label.": FAIL\n";
        throw new RuntimeException($label);
    }
    echo $label.": PASS\n";
};
$gps = static fn (): array => ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5,
    'captured_at' => now('UTC')->toIso8601String()];
$expectConstraint = static function (callable $operation) use ($check): void {
    try {
        $operation();
    } catch (\Illuminate\Database\QueryException $e) {
        $check($e->errorInfo[0] === '23000', 'EXPECTED_CONSTRAINT');

        return;
    }
    throw new RuntimeException('Expected database constraint rejection.');
};

if (($argv[1] ?? '') === 'worker') {
    try {
        [, , $name, $marker, $job, $payload] = $argv;
        $connect($name);
        if (! hash_equals((string) DB::table('field_test_guard')->value('marker'), $marker)) {
            throw new LogicException('Worker ownership mismatch.');
        }
        $data = json_decode(base64_decode($payload, true), true, 32, JSON_THROW_ON_ERROR);
        auth()->login(User::findOrFail($data['user_id']));
        // Parent releases all workers only after each reaches this barrier.
        DB::table('field_test_workers')->insert(['job' => $job, 'worker' => $data['worker'], 'ready' => true]);
        $deadline = microtime(true) + 20;
        while (! DB::table('field_test_jobs')->where('job', $job)->value('released')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timeout.');
            }
            usleep(10000);
        }
        $service = app(SupportActivityService::class);
        $result = match ($data['action']) {
            'create' => $service->create($data['input']),
            'start' => $service->start($data['uuid'], $gps()),
            'complete' => $service->complete($data['uuid']),
            'cancel' => $service->cancel($data['uuid'], 'Synthetic cancellation'),
        };
        echo json_encode(['ok' => true, 'uuid' => $result->uuid, 'status' => $result->status->value], JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error_type' => $e::class,
            'http_status' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null,
            'sql_state' => $e instanceof \Illuminate\Database\QueryException ? ($e->errorInfo[0] ?? null) : null,
            'driver_code' => $e instanceof \Illuminate\Database\QueryException ? ($e->errorInfo[1] ?? null) : null,
        ], JSON_THROW_ON_ERROR);
        exit(1);
    }
}

$name = 'field_support_136b1_test_'.bin2hex(random_bytes(8));
$marker = bin2hex(random_bytes(16));
$created = false;
$failed = false;
$stage = 'isolation';
$admin = null;
try {
    $admin = DB::connection()->getPdo();
    if (! $safeName($name)) {
        throw new LogicException('Unsafe target.');
    }
    // CREATE (not IF NOT EXISTS) establishes ownership. A collision is never reused.
    $admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    echo 'MYSQL_TEST_DB: '.$name.PHP_EOL;
    $connect($name);
    $isolation = DB::selectOne('SELECT @@transaction_isolation AS level')->level;
    echo 'MYSQL_ISOLATION: '.$isolation.PHP_EOL;
    DB::statement('CREATE TABLE field_test_guard (marker VARCHAR(64) NOT NULL)');
    DB::table('field_test_guard')->insert(['marker' => $marker]);
    DB::statement('CREATE TABLE field_test_jobs (job VARCHAR(64) PRIMARY KEY, released BOOLEAN NOT NULL)');
    DB::statement('CREATE TABLE field_test_workers (job VARCHAR(64), worker INT, ready BOOLEAN, PRIMARY KEY(job, worker))');
    $stage = 'migrations';
    $check(Artisan::call('migrate', ['--database' => 'field_isolated', '--force' => true]) === 0, 'MYSQL_MIGRATE_ALL');
    $identityMigration = require dirname(__DIR__, 2).'/database/migrations/2026_09_08_140000_add_employee_link_to_users_table.php';
    $activityMigration = require dirname(__DIR__, 2).'/database/migrations/2026_09_08_150000_create_vending_support_activities.php';
    $indexPath = dirname(__DIR__, 2).'/database/migrations/2026_09_08_160000_index_support_activity_queries.php';
    $indexMigration = is_file($indexPath) ? require $indexPath : null;
    $indexMigration?->down();
    $activityMigration->down();
    $identityMigration->down();
    $check(! Schema::hasColumn('users', 'employee_id') && ! Schema::hasTable('vending_support_activities'), 'EMPTY_ROLLBACK');
    $identityMigration->up();
    $activityMigration->up();
    $indexMigration?->up();
    $check(Schema::hasColumn('users', 'employee_id') && Schema::hasTable('vending_support_activity_events'), 'MIGRATION_RERUN');
    $columns = collect(DB::select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, DATA_TYPE, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?', [$name]));
    $column = static fn (string $table, string $field) => $columns->first(fn ($row) => $row->TABLE_NAME === $table && $row->COLUMN_NAME === $field);
    $check($column('users', 'employee_id')->COLUMN_TYPE === $column('employees', 'id')->COLUMN_TYPE
        && $column('users', 'employee_id')->IS_NULLABLE === 'YES', 'IDENTITY_FK_TYPES_NULLABLE');
    $check($column('vending_support_activities', 'employee_id')->COLUMN_TYPE === $column('employees', 'id')->COLUMN_TYPE
        && $column('vending_support_activities', 'vending_machine_id')->COLUMN_TYPE === $column('vending_machines', 'id')->COLUMN_TYPE
        && $column('vending_support_activities', 'support_ticket_id')->IS_NULLABLE === 'YES', 'ACTIVITY_FK_TYPES_NULLABLE');
    $check($column('vending_support_activities', 'status')->DATA_TYPE === 'varchar'
        && $column('vending_support_activities', 'activity_type')->DATA_TYPE === 'varchar'
        && $column('vending_support_activities', 'started_at')->DATA_TYPE === 'datetime'
        && (int) $column('vending_support_activities', 'started_latitude')->NUMERIC_SCALE === 7
        && (int) $column('vending_support_activities', 'started_accuracy_m')->NUMERIC_SCALE === 3
        && (int) $column('vending_support_activities', 'distance_m')->NUMERIC_SCALE === 2, 'MYSQL_STORAGE_TYPES');
    $receiptIndexes = collect(DB::select('SHOW INDEX FROM support_operations'))->where('Non_unique', 0)
        ->groupBy('Key_name')->map(fn ($index) => $index->sortBy('Seq_in_index')->pluck('Column_name')->implode(','));
    $check($receiptIndexes->contains('principal_key,operation_uuid'), 'CREATE_RECEIPT_DATABASE_UNIQUENESS');

    $employee = Employee::create(['employee_number' => 'FIELD-TEST-1', 'fortia_employee_id' => 990099,
        'source' => 'FORTIA', 'source_external_id' => 'FIELD-TEST-1', 'full_name' => 'Synthetic employee', 'status' => 'A']);
    $check($employee->user === null, 'EMPLOYEE_WITHOUT_USER');
    $user = User::factory()->create(['estatus' => true]);
    $otherUser = User::factory()->create(['estatus' => true]);
    $check($user->employee_id === null && $otherUser->employee_id === null, 'MULTIPLE_NULL_USER_LINKS');
    $user->employee()->associate($employee);
    $user->save();
    $expectConstraint(fn () => DB::table('users')->where('id', $otherUser->id)->update(['employee_id' => $employee->id]));
    $expectConstraint(fn () => DB::table('users')->where('id', $otherUser->id)->update(['employee_id' => 999999999]));
    $check($user->fresh()->employee->id === $employee->id, 'USER_EMPLOYEE_LINK');
    try {
        $identityMigration->down();
        throw new LogicException('Unsafe identity rollback succeeded.');
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'Cannot remove employee linkage'), 'LINKED_IDENTITY_ROLLBACK_REFUSED');
    }
    $role = Role::create(['name' => 'Synthetic field support']);
    $role->permissions()->attach(Permission::firstOrCreate(['module' => 'support', 'action' => 'manage'], ['name' => 'Synthetic manage'])->id);
    $user->roles()->attach($role);
    auth()->login($user);
    $machine = VendingMachine::create(['machine_code' => 'FIELD-TEST-1', 'status' => 'ACTIVE',
        'latitude' => 19.4326, 'longitude' => -99.1332, 'coordinate_source' => 'MANUAL', 'timezone' => 'UTC']);
    EmployeeMachineAssignment::create(['employee_id' => $employee->id, 'vending_machine_id' => $machine->id,
        'assignment_type' => 'TECHNICIAN', 'status' => 'ACTIVE', 'source' => 'TEST',
        'valid_from' => now()->subDay(), 'attendance_allowed' => false, 'enrollment_allowed' => false, 'maintenance_allowed' => true]);
    $geo = app(MachineGeofenceService::class)->create($machine, [
        'center_latitude' => 19.4326, 'center_longitude' => -99.1332, 'radius_m' => 50,
        'minimum_acceptable_accuracy_m' => 30, 'tolerance_m' => 10, 'status' => 'ACTIVE', 'source' => 'TEST',
    ]);
    $input = static fn (): array => ['client_operation_uuid' => (string) Str::uuid(),
        'vending_machine_id' => $machine->id, 'employee_id' => $employee->id,
        'activity_type' => 'MAINTENANCE', 'title' => 'Synthetic activity', 'description' => 'Disposable fixture'];
    $service = app(SupportActivityService::class);

    // Prove the snapshot/current-read distinction that concurrent receipt recovery needs.
    if ($isolation === 'REPEATABLE-READ') {
        $reader = DB::connection('mysql');
        $check($reader->selectOne('SELECT DATABASE() AS db')->db === $name, 'SNAPSHOT_PROBE_ISOLATED');
        $reader->beginTransaction();
        try {
            $reader->table('vending_support_activities')->count();
            $probe = $service->create($input());
            $old = $reader->table('vending_support_activities')->where('uuid', $probe->uuid)->first();
            $current = $reader->table('vending_support_activities')->where('uuid', $probe->uuid)->lockForUpdate()->first();
            $check($old === null && $current !== null, 'MYSQL_SNAPSHOT_VS_CURRENT_READ');
        } finally {
            $reader->rollBack();
            DB::disconnect('mysql');
        }
    }

    $run = static function (array $jobs) use ($name, $marker, $user): array {
        $job = (string) Str::uuid();
        DB::table('field_test_jobs')->insert(['job' => $job, 'released' => false]);
        $workers = [];
        try {
            foreach ($jobs as $index => $task) {
                $payload = base64_encode(json_encode($task + ['user_id' => $user->id, 'worker' => $index], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, __FILE__, 'worker', $name, $marker, $job, $payload],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
                if (! is_resource($process)) {
                    throw new RuntimeException('Could not create worker.');
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'pipes' => $pipes, 'output' => ''];
            }
            $deadline = microtime(true) + 20;
            while (DB::table('field_test_workers')->where('job', $job)->count() !== count($jobs)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Workers failed to reach barrier.');
                }
                usleep(10000);
            }
            DB::table('field_test_jobs')->where('job', $job)->update(['released' => true]);
            $deadline = microtime(true) + 30;
            do {
                $running = false;
                foreach ($workers as &$worker) {
                    $worker['output'] .= stream_get_contents($worker['pipes'][1]);
                    stream_get_contents($worker['pipes'][2]); // Never echo diagnostics that may contain credentials.
                    $running = proc_get_status($worker['process'])['running'] || $running;
                }
                unset($worker);
                if ($running) {
                    usleep(10000);
                }
            } while ($running && microtime(true) < $deadline);
            if ($running) {
                throw new RuntimeException('Concurrent workers timed out.');
            }
            $results = [];
            foreach ($workers as $worker) {
                $result = json_decode($worker['output'].stream_get_contents($worker['pipes'][1]), true);
                if (! is_array($result) || (! ($result['ok'] ?? false) && ($result['http_status'] ?? null) !== 409)) {
                    echo 'WORKER_FAILURE '.json_encode(is_array($result) ? array_intersect_key($result,
                        array_flip(['error_type', 'http_status', 'sql_state', 'driver_code'])) : ['error_type' => 'invalid_worker_result'], JSON_THROW_ON_ERROR).PHP_EOL;
                    throw new RuntimeException('Unexpected worker outcome.');
                }
                $results[] = $result;
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                if (proc_get_status($worker['process'])['running']) {
                    proc_terminate($worker['process']);
                }
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                proc_close($worker['process']);
            }
        }
    };

    $stage = 'concurrency';
    $data = $input();
    $before = VendingSupportActivity::count();
    $results = $run(array_fill(0, 2, ['action' => 'create', 'input' => $data]));
    $createOk = count(array_unique(array_column($results, 'uuid'))) === 1 && VendingSupportActivity::count() === $before + 1;
    echo 'CREATE_CONCURRENCY: '.($createOk ? 'PASS' : 'FAIL')." (2 barrier-synchronized processes)\n";
    $failed = ! $createOk;
    $activity = VendingSupportActivity::where('uuid', $results[0]['uuid'])->firstOrFail();
    if ($createOk) {
        $auditBefore = DB::table('audit_logs')->count();
        $replayed = $service->create($data);
        $check($replayed->id === $activity->id && DB::table('audit_logs')->count() === $auditBefore, 'CREATE_REPLAY_NO_SIDE_EFFECT');
        try {
            $service->create(array_replace($data, ['title' => 'Changed operation payload']));
            throw new LogicException('Conflicting operation accepted.');
        } catch (HttpExceptionInterface $e) {
            $check($e->getStatusCode() === 409, 'CREATE_TAMPERING_CONFLICT');
        }
    }
    $singleWinner = static function (array $results) use ($check): void {
        $check(count(array_filter($results, fn ($r) => $r['ok'])) === 1
            && count(array_filter($results, fn ($r) => ! $r['ok'] && $r['http_status'] === 409)) === 1, 'ONE_WINNER_ONE_CONFLICT');
    };
    $singleWinner($run(array_fill(0, 2, ['action' => 'start', 'uuid' => $activity->uuid])));
    $check($activity->fresh()->status->value === 'IN_PROGRESS'
        && DB::table('vending_support_activity_events')->where('activity_id', $activity->id)->where('kind', 'started')->count() === 1, 'START_CONCURRENCY');
    $singleWinner($run(array_fill(0, 2, ['action' => 'complete', 'uuid' => $activity->uuid])));
    $check($activity->fresh()->status->value === 'COMPLETED'
        && DB::table('vending_support_activity_events')->where('activity_id', $activity->id)->where('kind', 'completed')->count() === 1, 'COMPLETE_CONCURRENCY');
    foreach (range(1, 3) as $iteration) {
        $race = $service->create($input());
        $service->start($race->uuid, $gps());
        $singleWinner($run([['action' => 'complete', 'uuid' => $race->uuid], ['action' => 'cancel', 'uuid' => $race->uuid]]));
        $race->refresh();
        $check(in_array($race->status->value, ['COMPLETED', 'CANCELLED'], true)
            && (($race->completed_at !== null) xor ($race->cancelled_at !== null))
            && DB::table('vending_support_activity_events')->where('activity_id', $race->id)->whereIn('kind', ['completed', 'cancelled'])->count() === 1, 'COMPLETE_VS_CANCEL_'.$iteration);
    }

    $stage = 'history';
    $ticketData = app(SupportTicketService::class)->create(SupportActor::user($user), [
        'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id,
        'category' => 'OTHER', 'title' => 'Synthetic ticket', 'description' => 'Isolated only',
    ])['ticket'];
    $ticket = SupportTicket::where('uuid', $ticketData['uuid'])->firstOrFail();
    $ticketBefore = $ticket->getRawOriginal();
    $linked = $service->create($input() + ['support_ticket_uuid' => $ticket->uuid]);
    $service->create($input() + ['support_ticket_uuid' => $ticket->uuid]);
    $service->start($linked->uuid, $gps());
    $service->complete($linked->uuid);
    $check($ticket->activities()->count() === 2 && $ticketBefore === $ticket->fresh()->getRawOriginal(), 'TICKET_ONE_TO_MANY_NO_AUTOCLOSE');
    foreach (['users' => $user->id, 'employees' => $employee->id, 'vending_machines' => $machine->id,
        'support_tickets' => $ticket->id, 'machine_geofences' => $geo->id, 'vending_support_activities' => $linked->id] as $table => $id) {
        $expectConstraint(fn () => DB::table($table)->where('id', $id)->delete());
    }
    $snapshot = $linked->fresh()->getRawOriginal();
    app(MachineGeofenceService::class)->create($machine, ['center_latitude' => 19.4326,
        'center_longitude' => -99.1332, 'radius_m' => 100, 'minimum_acceptable_accuracy_m' => 30,
        'tolerance_m' => 10, 'status' => 'ACTIVE', 'source' => 'TEST']);
    $check($snapshot === $linked->fresh()->getRawOriginal(), 'GEOFENCE_SNAPSHOT_PRESERVED');
    foreach (['RESOLVED', 'CLOSED'] as $ticketStatus) {
        app(SupportTicketService::class)->transition(SupportActor::user($user), $ticket->uuid, [
            'client_operation_uuid' => (string) Str::uuid(), 'status' => $ticketStatus, 'resolution' => 'Synthetic resolution',
        ]);
    }
    $check($ticket->activities()->count() === 2 && $snapshot === $linked->fresh()->getRawOriginal(), 'TICKET_CLOSURE_PRESERVES_ACTIVITIES');
    try {
        $activityMigration->down();
        throw new LogicException('Unsafe historical rollback succeeded.');
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'Cannot remove operational activity history'), 'HISTORICAL_ROLLBACK_REFUSED');
    }
    $copy = $snapshot;
    unset($copy['id']);
    $expectConstraint(fn () => DB::table('vending_support_activities')->insert($copy));
    $check((int) $snapshot['geofence_version'] === (int) $geo->version
        && abs((float) $snapshot['started_latitude'] - 19.4326) < 0.0000001
        && (float) $snapshot['started_accuracy_m'] === 5.0 && (float) $snapshot['distance_m'] === 0.0, 'SNAPSHOT_PRECISION');
    $constraints = DB::select("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME IN ('vending_support_activities','vending_support_activity_events')", [$name]);
    $check(count($constraints) >= 12 && collect($constraints)->every(fn ($row) => $row->DELETE_RULE === 'RESTRICT'), 'HISTORICAL_FOREIGN_KEYS_RESTRICT');

    $stage = 'scale';
    $machines = [];
    for ($i = 2; $i <= 1000; $i++) {
        $machines[] = ['uuid' => (string) Str::uuid(), 'machine_code' => 'FIELD-SCALE-'.$i, 'status' => 'ACTIVE', 'timezone' => 'UTC',
            'created_at' => now('UTC'), 'updated_at' => now('UTC')];
    }
    foreach (array_chunk($machines, 200) as $chunk) {
        DB::table('vending_machines')->insert($chunk);
    }
    $machineIds = DB::table('vending_machines')->orderBy('id')->pluck('id')->all();
    $scale = [];
    for ($i = 0; $i < 10000; $i++) {
        $status = $i % 20 === 0 ? 'ASSIGNED' : ($i % 20 === 1 ? 'IN_PROGRESS' : 'COMPLETED');
        $scale[] = ['uuid' => (string) Str::uuid(), 'vending_machine_id' => $machineIds[$i % 1000],
            'employee_id' => $employee->id, 'support_ticket_id' => $i % 50 === 0 ? $ticket->id : null,
            'activity_type' => 'MAINTENANCE', 'status' => $status, 'title' => 'Scale fixture',
            'presence_policy' => 'FIELD_PHYSICAL_V1', 'requires_physical_presence' => true,
            'created_at' => now('UTC')->subSeconds(10000 - $i), 'updated_at' => now('UTC')];
    }
    foreach (array_chunk($scale, 250) as $chunk) {
        DB::table('vending_support_activities')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE vending_support_activities');
    $queries = [
        'machine' => ["SELECT id FROM vending_support_activities WHERE vending_machine_id = ? AND status = 'COMPLETED' ORDER BY id DESC LIMIT 25", [$machine->id]],
        'employee' => ["SELECT id FROM vending_support_activities WHERE employee_id = ? AND status = 'ASSIGNED' ORDER BY id DESC LIMIT 25", [$employee->id]],
        'status' => ["SELECT id FROM vending_support_activities WHERE status = 'ASSIGNED' ORDER BY created_at DESC, id DESC LIMIT 25", []],
        'open' => ["SELECT id FROM vending_support_activities WHERE status IN ('ASSIGNED','IN_PROGRESS') ORDER BY status, created_at DESC, id DESC LIMIT 25", []],
        'ticket' => ['SELECT id FROM vending_support_activities WHERE support_ticket_id = ? ORDER BY id DESC LIMIT 25', [$ticket->id]],
        'date' => ['SELECT id FROM vending_support_activities WHERE created_at >= ? ORDER BY created_at DESC, id DESC LIMIT 25', [now('UTC')->subMinute()->toDateTimeString()]],
    ];
    foreach ($queries as $label => [$sql, $bindings]) {
        $plan = DB::select('EXPLAIN '.$sql, $bindings)[0];
        echo 'EXPLAIN '.json_encode(['query' => $label, 'type' => $plan->type, 'key' => $plan->key, 'rows' => $plan->rows, 'extra' => $plan->Extra], JSON_THROW_ON_ERROR).PHP_EOL;
        $check($plan->key !== null && $plan->type !== 'ALL', 'INDEX_'.$label);
        $check(count(DB::select($sql, $bindings)) <= 25, 'BOUNDED_'.$label);
    }
    echo 'SYNTHETIC_SCALE: '.count($machineIds).' machines / '.VendingSupportActivity::count()." activities\n";
    $check(DB::table('attendance_logs')->count() === 0 && DB::table('vending_attendance_events')->count() === 0, 'ISOLATED_ATTENDANCE_ZERO');
    echo 'MYSQL_VALIDATION: '.($failed ? 'FAIL' : 'PASS').PHP_EOL;
} catch (Throwable $e) {
    // Deliberately omit exception messages/SQL/bindings/connection configuration.
    echo 'MYSQL_VALIDATION: FAIL stage='.$stage.' error_type='.$e::class
        .' sql_state='.($e instanceof \Illuminate\Database\QueryException ? ($e->errorInfo[0] ?? 'none') : 'none').PHP_EOL;
    $failed = true;
} finally {
    if ($created) {
        if (! $safeName($name)) {
            throw new LogicException('Refused unsafe schema cleanup.');
        }
        // Only the exact schema successfully created above may be removed.
        foreach (array_keys(config('database.connections')) as $connection) {
            DB::disconnect($connection);
        }
        $admin->exec('DROP DATABASE `'.$name.'`');
        echo 'MYSQL_TEST_CLEANUP: REMOVED '.$name.PHP_EOL;
    }
}
exit($failed ? 1 : 0);
