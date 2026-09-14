<?php

// Legacy local-DB harness retired after the config-cache incident.
fwrite(STDERR, "TEST_DATABASE_SAFETY_BLOCKED environment=UNKNOWN driver=UNKNOWN database=UNKNOWN reason=legacy harness disabled; use tests/Support/disposable_mysql.php\n");
exit(1);


// Opt-in, local-only MySQL concurrency harness. It creates and destroys ONLY its
// newly allocated support_phase13_test_<hex> database; never uses the operational DB.
use App\Models\Device;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportCorrelation;
use App\Models\SupportEvidence;
use App\Models\SupportIntegration;
use App\Models\SupportOperation;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportAutomationService;
use App\Services\Support\SupportEvidenceService;
use App\Services\Support\SupportPolicyService;
use App\Services\Support\SupportTicketService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || DB::connection()->getDriverName() !== 'mysql') {
    fwrite(STDERR, "BLOCKED: local MySQL required\n");
    exit(2);
}
$baseConfig = DB::connection()->getConfig();
$connect = function (string $name) use ($baseConfig): void {
    if (! preg_match('/^support_phase13_test_[a-f0-9]{16}$/', $name) || $name === $baseConfig['database']) {
        throw new RuntimeException('Unsafe test database target.');
    }
    $isolated = $baseConfig;
    unset($isolated['name']);
    $isolated['database'] = $name;
    // Legacy migrations explicitly address fortia_mock; redirect EVERY configured
    // connection inside this process, including Schema's resolved default builder.
    foreach (array_keys(config('database.connections')) as $connection) {
        DB::purge($connection);
        config(['database.connections.'.$connection => $isolated]);
    }
    config(['database.connections.support_concurrency' => $isolated]);
    DB::purge('support_concurrency');
    DB::setDefaultConnection('support_concurrency');
    \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
    if (DB::selectOne('SELECT DATABASE() AS db')->db !== $name) {
        throw new RuntimeException('Test connection identity mismatch.');
    }
};
$privateDisk = function (string $directory): void {
    $temporary = realpath(sys_get_temp_dir());
    $resolved = realpath($directory);
    if ($temporary === false || $resolved === false || dirname($resolved) !== $temporary
        || ! preg_match('/^support_phase13_files_[a-f0-9]{16}$/', basename($resolved))
        || is_link($directory)) {
        throw new RuntimeException('Unsafe test storage target.');
    }
    config(['support.evidence.disk' => 'support_concurrency_private',
        'filesystems.disks.support_concurrency_private' => [
            'driver' => 'local', 'root' => $resolved, 'visibility' => 'private',
            'serve' => false, 'throw' => true,
        ]]);
    Storage::forgetDisk('support_concurrency_private');
};
$imageBytes = function (): string {
    $image = imagecreatetruecolor(40, 20);
    imagefilledrectangle($image, 0, 0, 39, 19, imagecolorallocate($image, 20, 80, 140));
    ob_start();
    try {
        imagepng($image);

        return ob_get_contents();
    } finally {
        ob_end_clean();
        imagedestroy($image);
    }
};
if (($argv[1] ?? '') === 'worker') {
    try {
        [, , $name, $operation, $external, $start] = $argv;
        $connect($name);
        $type = $argv[6] ?? 'create';
        $context = json_decode(base64_decode($argv[7] ?? 'e30=', true), true, 512, JSON_THROW_ON_ERROR);
        if ($type === 'correlation') {
            config(['support.automation.enabled' => true]);
            usleep(max(0, (int) (((float) $start - microtime(true)) * 1000000)));
            $result = app(SupportAutomationService::class)->scan(100, (int) $context['machine_id']);
            fwrite(STDOUT, json_encode(['ok' => true, 'processed' => $result['processed'], 'observations' => $result['observations']], JSON_THROW_ON_ERROR));
            exit(0);
        }
        if (in_array($type, ['assign', 'close', 'evidence'], true)) {
            $actor = SupportActor::user(User::query()->with('roles.permissions')->findOrFail($context['actor_id']));
            $ticket = SupportTicket::query()->where('uuid', $context['ticket_uuid'])->firstOrFail();
            if ($type === 'evidence') {
                $privateDisk($context['storage_directory']);
                $bytes = $imageBytes();
            }
            usleep(max(0, (int) (((float) $start - microtime(true)) * 1000000)));
            $result = match ($type) {
                'assign' => app(SupportTicketService::class)->assign($actor, $ticket->uuid, [
                    'client_operation_uuid' => $operation,
                    'assignee_id' => $context['assignee_ids'][$context['worker_index'] % count($context['assignee_ids'])],
                ]),
                'close' => app(SupportTicketService::class)->transition($actor, $ticket->uuid, [
                    'client_operation_uuid' => $operation, 'status' => 'CLOSED',
                ]),
                'evidence' => app(SupportEvidenceService::class)->upload($actor, $ticket, $context['evidence_uuid'], $bytes, 'image/png'),
            };
            fwrite(STDOUT, json_encode(['ok' => true, 'operation' => $operation,
                'uuid' => $result['ticket']['uuid'] ?? $result['evidence']['uuid'],
                'sha256' => $result['evidence']['sha256'] ?? null,
                'status' => $result['ticket']['status'] ?? $result['evidence']['status'],
            ], JSON_THROW_ON_ERROR));
            exit(0);
        }
        $machine = VendingMachine::query()->sole();
        $actor = $external === 'yes'
            ? SupportActor::integration(SupportIntegration::query()->sole(), ['support.tickets.create'])
            : SupportActor::system();
        $data = ['client_operation_uuid' => $operation, 'vending_machine_id' => $machine->id,
            'category' => 'OTHER', 'title' => 'Concurrent fixture', 'description' => 'Isolated test data'];
        if ($external === 'yes') {
            $data['external_reference'] = 'same-reference';
        } else {
            $data['source'] = 'SYSTEM';
        }
        usleep(max(0, (int) (((float) $start - microtime(true)) * 1000000)));
        $result = app(SupportTicketService::class)->create($actor, $data);
        fwrite(STDOUT, json_encode(['ok' => true, 'uuid' => $result['ticket']['uuid'], 'folio' => $result['ticket']['folio']], JSON_THROW_ON_ERROR));
        exit(0);
    } catch (Throwable $error) {
        // Do not print connection details or payloads on failure.
        fwrite(STDOUT, json_encode(['ok' => false, 'error_type' => $error::class,
            'code' => (string) $error->getCode(), 'operation' => $operation ?? null,
            'sql_state' => $error instanceof \Illuminate\Database\QueryException ? ($error->errorInfo[0] ?? null) : null,
            'driver_code' => $error instanceof \Illuminate\Database\QueryException ? ($error->errorInfo[1] ?? null) : null,
            'http_status' => $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : null,
        ]));
        exit(1);
    }
}

$name = 'support_phase13_test_'.bin2hex(random_bytes(8));
$admin = DB::connection()->getPdo();
$created = false;
$storageDirectory = null;
$storageCreated = false;
try {
    $admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $connect($name);
    if (Artisan::call('migrate', ['--database' => 'support_concurrency', '--force' => true]) !== 0) {
        throw new RuntimeException('Isolated migrations failed.');
    }
    $machine = VendingMachine::create(['machine_code' => 'CONCURRENCY-ONLY', 'status' => 'DRAFT', 'timezone' => 'UTC']);
    $integration = SupportIntegration::create(['uuid' => (string) Str::uuid(), 'system_key' => 'CONCURRENCY_TEST', 'name' => 'Isolated service', 'active' => true]);
    $integration->machines()->attach($machine);
    $run = function (array $operations, bool $external = false, string $type = 'create', array $context = [], bool $allowConflict = false) use ($name): array {
        $workers = [];
        $start = (string) (microtime(true) + 1);
        foreach ($operations as $index => $operation) {
            $workerContext = base64_encode(json_encode($context + ['worker_index' => $index], JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $name, $operation, $external ? 'yes' : 'no', $start, $type, $workerContext],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('Worker could not start.');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'output' => '', 'error' => ''];
        }
        $deadline = microtime(true) + 30;
        do {
            $running = false;
            foreach ($workers as &$worker) {
                $worker['output'] .= stream_get_contents($worker['pipes'][1]);
                $worker['error'] .= stream_get_contents($worker['pipes'][2]);
                $running = proc_get_status($worker['process'])['running'] || $running;
            }
            unset($worker);
            if ($running) {
                usleep(20000);
            }
        } while ($running && microtime(true) < $deadline);
        $results = [];
        foreach ($workers as $worker) {
            if ($running) {
                proc_terminate($worker['process']);
            }
            $output = $worker['output'].stream_get_contents($worker['pipes'][1]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
            $result = json_decode($output, true);
            if ($running || (! ($result['ok'] ?? false) && ! ($allowConflict && ($result['http_status'] ?? null) === 409))) {
                throw new RuntimeException('Concurrent '.$type.' worker failed: '.($result['error_type'] ?? 'no safe result')
                    .' SQLSTATE='.($result['sql_state'] ?? 'none').' driver_code='.($result['driver_code'] ?? 'none'));
            }
            $results[] = $result;
        }

        return $results;
    };
    $same = (string) Str::uuid();
    $duplicates = $run(array_fill(0, 6, $same));
    if (count(array_unique(array_column($duplicates, 'uuid'))) !== 1 || SupportTicket::count() !== 1) {
        throw new RuntimeException('Operation race created duplicates.');
    }
    echo "MYSQL_OPERATION_RACE: PASS (6 workers, 1 ticket)\n";
    $different = $run(array_map(fn () => (string) Str::uuid(), range(1, 6)));
    if (count(array_unique(array_column($different, 'folio'))) !== 6 || SupportTicket::count() !== 7) {
        throw new RuntimeException('Folio race failed.');
    }
    echo "MYSQL_FOLIO_RACE: PASS (6 distinct simultaneous creations)\n";
    $external = $run(array_map(fn () => (string) Str::uuid(), range(1, 6)), true);
    if (count(array_unique(array_column($external, 'uuid'))) !== 1 || SupportTicket::count() !== 8) {
        throw new RuntimeException('External reference race created duplicates.');
    }
    if (SupportTicketEvent::orderBy('sequence')->pluck('sequence')->map(fn ($v) => (int) $v)->all() !== range(1, 8)) {
        throw new RuntimeException('Event sequence failed.');
    }
    echo "MYSQL_EXTERNAL_REFERENCE_RACE: PASS (6 operations, 1 reference)\n";
    echo "MYSQL_TIMELINE_SEQUENCE: PASS (unique contiguous committed events)\n";
    $freshOperations = fn (): array => array_map(fn () => (string) Str::uuid(), range(1, 6));
    $role = Role::query()->create(['name' => 'Concurrency support fixture']);
    $permission = Permission::query()->firstOrCreate(['module' => 'support', 'action' => 'manage'], ['name' => 'Support fixture manage']);
    $role->permissions()->attach($permission);
    $users = collect(range(1, 2))->map(function (int $index) use ($role): User {
        $user = User::factory()->create(['name' => 'Synthetic support '.$index, 'estatus' => true]);
        $user->roles()->attach($role);

        return $user;
    });
    $actor = SupportActor::user($users->first()->load('roles.permissions'));
    $machine->update(['status' => 'ACTIVE']);
    $device = Device::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'device_serial' => 'CONCURRENT-'.Str::random(12),
        'vending_machine_id' => $machine->id, 'status' => 'ACTIVE', 'is_active' => true,
        'platform' => 'android', 'app_version' => '1.0.0', 'last_heartbeat_at' => now(),
        'last_seen_at' => now(), 'pending_events_count' => 200, 'clock_drift_seconds' => 0,
        'config_version_applied' => $machine->config_version, 'employee_manifest_version_applied' => 1,
    ]);
    $policy = app(SupportPolicyService::class)->publish($actor, [
        'client_operation_uuid' => (string) Str::uuid(), 'label' => 'DEMO isolated MySQL race',
        'is_demo' => true, 'active' => true, 'valid_from' => now()->subMinute()->toIso8601String(),
        'valid_until' => now()->addHour()->toIso8601String(),
        'payload' => ['sla' => ['enabled' => false], 'automation' => ['enabled' => true, 'rules' => [[
            'key' => 'OUTBOX_HIGH', 'enabled' => true, 'source' => 'FLEET',
            'persistence_seconds' => 0, 'cooldown_seconds' => 600, 'category' => 'APPLICATION',
            'severity' => 'HIGH', 'priority' => 'NORMAL', 'machine_ids' => [$machine->id],
        ]]]],
    ]);
    if ($policy['version'] !== 1 || (int) DB::table('support_runtime_cursors')->where('key', 'policy_version')->value('value') !== 1) {
        throw new RuntimeException('Isolated policy version counter failed.');
    }
    $signals = $run($freshOperations(), type: 'correlation', context: ['machine_id' => $machine->id]);
    $correlation = SupportCorrelation::query()->sole();
    $automated = SupportTicket::query()->findOrFail($correlation->ticket_id);
    if (array_sum(array_column($signals, 'observations')) !== 6 || SupportTicket::count() !== 9
        || $automated->source !== 'AUTOMATED_ALERT' || $correlation->signal_state !== 'ACTIVE'
        || $automated->events()->count() !== 2) {
        throw new RuntimeException('Correlation race did not converge to one lifecycle.');
    }
    $audits = DB::table('audit_logs')->count();
    $run($freshOperations(), type: 'correlation', context: ['machine_id' => $machine->id]);
    if (SupportTicket::count() !== 9 || $automated->events()->count() !== 2 || DB::table('audit_logs')->count() !== $audits) {
        throw new RuntimeException('Repeated active signals created an event or audit storm.');
    }
    echo 'MYSQL_CORRELATION_RACE: PASS (6 workers, 1 correlation/ticket, repeat without storm)'.PHP_EOL;
    $device->update(['last_heartbeat_at' => now()->subHour()]);
    $run($freshOperations(), type: 'correlation', context: ['machine_id' => $machine->id]);
    if ($correlation->fresh()->signal_state !== 'UNKNOWN' || $automated->events()->where('kind', 'support.ticket.recovery')->count() !== 0) {
        throw new RuntimeException('Unknown signal fabricated a recovery.');
    }
    $device->update(['last_heartbeat_at' => now(), 'pending_events_count' => 0]);
    $run($freshOperations(), type: 'correlation', context: ['machine_id' => $machine->id]);
    if ($correlation->fresh()->signal_state !== 'RECOVERED' || $automated->events()->where('kind', 'support.ticket.recovery')->count() !== 1
        || $automated->fresh()->status !== 'OPEN' || SupportTicket::count() !== 9) {
        throw new RuntimeException('Concurrent recovery did not remain unique and non-closing.');
    }
    echo 'MYSQL_RECOVERY_RACE: PASS (6 workers, UNKNOWN non-recovery, 1 recovery event, ticket OPEN)'.PHP_EOL;
    $assignmentOperations = $freshOperations();
    $assignmentContext = ['actor_id' => $actor->id, 'ticket_uuid' => $automated->uuid, 'assignee_ids' => $users->pluck('id')->all()];
    $run($assignmentOperations, type: 'assign', context: $assignmentContext);
    $assignments = $automated->events()->where('kind', 'support.ticket.assigned')->orderBy('sequence')->get();
    $previousAssignee = null;
    foreach ($assignments as $assignment) {
        if (($assignment->metadata['previous_assignee_id'] ?? null) !== $previousAssignee
            || ! in_array((int) $assignment->metadata['assignee_id'], $users->pluck('id')->all(), true)
            || (int) $assignment->actor_id !== $actor->id) {
            throw new RuntimeException('Assignment history lost its previous-owner chain.');
        }
        $previousAssignee = (int) $assignment->metadata['assignee_id'];
    }
    $assignmentReceipts = SupportOperation::query()->where('principal_key', $actor->key())->whereIn('operation_uuid', $assignmentOperations)->count();
    if ($assignments->count() !== 6 || $assignmentReceipts !== 6 || (int) $automated->fresh()->assignee_id !== $previousAssignee) {
        throw new RuntimeException('Assignment race lost a mutation or receipt.');
    }
    $run([$assignmentOperations[0]], type: 'assign', context: $assignmentContext);
    if ($automated->events()->where('kind', 'support.ticket.assigned')->count() !== 6 || (int) $automated->fresh()->assignee_id !== $previousAssignee) {
        throw new RuntimeException('Assignment replay mutated the final owner.');
    }
    echo 'MYSQL_ASSIGNMENT_RACE: PASS (6 workers, 2 assignees, consistent history/receipts, replay read-only)'.PHP_EOL;
    app(SupportTicketService::class)->transition($actor, $automated->uuid, [
        'client_operation_uuid' => (string) Str::uuid(), 'status' => 'RESOLVED', 'resolution' => 'Isolated concurrency fixture resolved.',
    ]);
    $closeOperations = $freshOperations();
    $closed = $run($closeOperations, type: 'close', context: ['actor_id' => $actor->id, 'ticket_uuid' => $automated->uuid], allowConflict: true);
    $winners = array_values(array_filter($closed, fn (array $result): bool => $result['ok']));
    $rejected = array_filter($closed, fn (array $result): bool => ($result['http_status'] ?? null) === 409);
    if (count($winners) !== 1 || count($rejected) !== 5 || $automated->fresh()->status !== 'CLOSED'
        || $automated->events()->where('kind', 'support.ticket.closed')->count() !== 1
        || SupportOperation::query()->where('principal_key', $actor->key())->whereIn('operation_uuid', $closeOperations)->count() !== 1) {
        throw new RuntimeException('Terminal transition race did not have exactly one committed winner.');
    }
    $closedEvents = $automated->events()->count();
    $run([$winners[0]['operation']], type: 'close', context: ['actor_id' => $actor->id, 'ticket_uuid' => $automated->uuid]);
    if ($automated->events()->count() !== $closedEvents) {
        throw new RuntimeException('Terminal transition replay appended history.');
    }
    echo 'MYSQL_TERMINAL_TRANSITION_RACE: PASS (6 workers, 1 commit, 5 conflicts, no partial receipts)'.PHP_EOL;
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($temporaryRoot === false) {
        throw new RuntimeException('Temporary storage root is unavailable.');
    }
    $storageDirectory = $temporaryRoot.DIRECTORY_SEPARATOR.'support_phase13_files_'.bin2hex(random_bytes(8));
    if (! mkdir($storageDirectory, 0700)) {
        throw new RuntimeException('Could not allocate isolated evidence storage.');
    }
    $storageCreated = true;
    $privateDisk($storageDirectory);
    $evidenceTicket = SupportTicket::query()->where('status', 'OPEN')->orderBy('id')->firstOrFail();
    $bytes = $imageBytes();
    $reservation = app(SupportEvidenceService::class)->initiate($actor, $evidenceTicket, [
        'client_operation_uuid' => (string) Str::uuid(), 'mime' => 'image/png',
        'size_bytes' => strlen($bytes), 'upload_sha256' => hash('sha256', $bytes),
    ]);
    $evidenceResults = $run($freshOperations(), type: 'evidence', context: [
        'actor_id' => $actor->id, 'ticket_uuid' => $evidenceTicket->uuid,
        'evidence_uuid' => $reservation['evidence']['uuid'], 'storage_directory' => $storageDirectory,
    ]);
    $evidence = SupportEvidence::query()->sole();
    $disk = Storage::disk('support_concurrency_private');
    $files = $disk->allFiles();
    sort($files);
    $expectedFiles = [$evidence->storage_key, $evidence->thumbnail_key];
    sort($expectedFiles);
    if ($evidence->status !== 'CONFIRMED' || count(array_unique(array_column($evidenceResults, 'uuid'))) !== 1
        || count(array_unique(array_column($evidenceResults, 'sha256'))) !== 1
        || $evidenceResults[0]['uuid'] !== $evidence->uuid || $evidenceResults[0]['sha256'] !== $evidence->sha256
        || $evidence->upload_sha256 !== hash('sha256', $bytes)
        || $evidence->sha256 !== hash('sha256', $disk->get($evidence->storage_key))
        || $evidence->thumbnail_sha256 !== hash('sha256', $disk->get($evidence->thumbnail_key))
        || $files !== $expectedFiles || $evidence->disk !== 'support_concurrency_private'
        || $evidenceTicket->events()->where('kind', 'support.evidence.created')->count() !== 1) {
        throw new RuntimeException('Evidence confirmation race duplicated metadata, files or events.');
    }
    echo 'MYSQL_EVIDENCE_CONFIRMATION_RACE: PASS (6 workers, 1 evidence/event, matching hashes, 2 private files)'.PHP_EOL;
    $sequences = SupportTicketEvent::orderBy('sequence')->pluck('sequence')->map(fn ($value) => (int) $value)->all();
    if ($sequences !== range(1, count($sequences)) || (int) DB::table('support_runtime_cursors')->where('key', 'timeline_sequence')->value('value') !== count($sequences)) {
        throw new RuntimeException('Global timeline counter diverged after domain races.');
    }
    echo 'MYSQL_FINAL_TIMELINE_SEQUENCE: PASS (ordered committed events and counter agree)'.PHP_EOL;
    $queries = [
        'open' => "SELECT id FROM support_tickets WHERE status = 'OPEN' ORDER BY updated_at,id LIMIT 50",
        'machine' => 'SELECT id FROM support_tickets WHERE vending_machine_id = 1 ORDER BY updated_at,id LIMIT 50',
        'assignee' => "SELECT id FROM support_tickets WHERE assignee_id = 1 AND status = 'OPEN' ORDER BY id LIMIT 50",
        'sla' => 'SELECT id FROM support_tickets WHERE response_breached = 0 AND response_due_at <= UTC_TIMESTAMP() ORDER BY response_due_at LIMIT 100',
        'correlation' => "SELECT id FROM support_correlations WHERE correlation_key = '".str_repeat('0', 64)."' LIMIT 1",
    ];
    foreach ($queries as $label => $sql) {
        if ($label === 'correlation') {
            $sql = 'SELECT id FROM support_correlations WHERE correlation_key = '.DB::connection()->getPdo()->quote($correlation->correlation_key).' LIMIT 1';
        }
        $plan = DB::select('EXPLAIN '.$sql)[0];
        if ($label === 'correlation' && ($plan->key ?? null) !== 'support_correlations_correlation_key_unique') {
            throw new RuntimeException('Real correlation lookup did not select its unique index.');
        }
        echo 'EXPLAIN '.$label.': type='.($plan->type ?? 'none').' possible_keys='.($plan->possible_keys ?? 'none').' key='.($plan->key ?? 'none').PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'MYSQL_CONCURRENCY: FAIL ('.$error::class.': '.$error->getMessage().")\n");
    $failure = true;
} finally {
    if ($storageCreated && $storageDirectory !== null && is_dir($storageDirectory)) {
        $privateDisk($storageDirectory); // Revalidate the exact owned temporary root before recursive cleanup.
        $root = realpath($storageDirectory);
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            $resolved = realpath($path);
            if ($entry->isLink() || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Refused unsafe evidence fixture cleanup.');
            }
            $entry->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($root);
        echo 'TEMPORARY_TEST_STORAGE: REMOVED (synthetic private fixtures only)'.PHP_EOL;
    }
    if ($created) {
        DB::disconnect('support_concurrency');
        if (! preg_match('/^support_phase13_test_[a-f0-9]{16}$/', $name) || $name === $baseConfig['database']) {
            throw new RuntimeException('Refused unsafe cleanup.');
        }
        $admin->exec('DROP DATABASE `'.$name.'`');
        echo "TEMPORARY_TEST_DATABASE: REMOVED (reproducible fixtures only)\n";
    }
}
exit(isset($failure) ? 1 : 0);
