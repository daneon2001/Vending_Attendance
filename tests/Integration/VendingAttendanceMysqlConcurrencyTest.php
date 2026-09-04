<?php

namespace Tests\Integration;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\VendingMachine;
use App\Services\Vending\MachineAssignmentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VendingAttendanceMysqlConcurrencyTest extends TestCase
{
    private PDO $admin;

    private string $database;

    private array $mysql;

    private bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysql = array_merge(
            config('database.connections.mysql'),
            array_intersect_key(config('database.connections.fortia_mock'), array_flip([
                'host', 'port', 'username', 'password', 'unix_socket',
            ])),
        );
        $host = (string) ($this->mysql['host'] ?? '');
        $this->assertContains($host, ['127.0.0.1', 'localhost'], 'Concurrency DB must be loopback-only.');
        $this->database = 'vending_attendance_testing';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $this->mysql['port'], $this->database);
        $this->admin = new PDO($dsn, $this->mysql['username'], $this->mysql['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $tables = (int) $this->admin->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'vending_attendance_testing'",
        )->fetchColumn();
        if ($tables !== 0) {
            throw new RuntimeException('The dedicated MySQL concurrency database must be empty before the test.');
        }

        config(['database.connections.phase4_mysql' => array_merge($this->mysql, ['database' => $this->database])]);
        DB::purge('phase4_mysql');
        DB::setDefaultConnection('phase4_mysql');
        $this->migrated = true;
        Artisan::call('migrate', ['--database' => 'phase4_mysql', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->migrated && $this->database === 'vending_attendance_testing') {
            Artisan::call('db:wipe', ['--database' => 'phase4_mysql', '--force' => true]);
        }
        DB::disconnect('phase4_mysql');

        parent::tearDown();
    }

    public function test_real_mysql_serializes_duplicate_single_and_batch_ingestion(): void
    {
        [$device, $payload] = $this->fixture();
        $singleResults = $this->runConcurrently('single', $device, $payload);
        $this->assertSame(['DUPLICATE', 'STORED'], $this->statuses($singleResults));
        $this->assertSame(1, DB::table('vending_attendance_events')->count());

        $batch = [];
        for ($index = 0; $index < 10; $index++) {
            $batch[] = array_replace($payload, ['event_uuid' => (string) Str::uuid()]);
        }
        $batchResults = $this->runConcurrently('batch', $device, $batch);
        $statuses = collect($batchResults)->flatMap(fn (array $result) => $result['results'])->pluck('status')->countBy();

        $this->assertSame(10, $statuses->get('STORED'));
        $this->assertSame(10, $statuses->get('DUPLICATE'));
        $this->assertSame(11, DB::table('vending_attendance_events')->count());
        $this->assertSame(11, DB::table('vending_attendance_events')->distinct()->count('event_uuid'));

        $uniqueIndex = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS aggregate
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'vending_attendance_events'
              AND index_name = 'vending_attendance_events_uuid_unique'
              AND non_unique = 0
            SQL);
        $this->assertSame(1, (int) $uniqueIndex->aggregate);
    }

    private function fixture(): array
    {
        $machine = VendingMachine::query()->create([
            'machine_code' => 'MYSQL-CONCURRENCY',
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'coordinate_source' => 'MANUAL',
            'coordinates_verified' => true,
            'timezone' => 'America/Mexico_City',
            'status' => 'ACTIVE',
        ]);
        $employee = Employee::query()->create([
            'fortia_employee_id' => 991001,
            'full_name' => 'Concurrency Test',
            'status' => 'A',
        ]);
        $assignment = app(MachineAssignmentService::class)->create($machine, [
            'employee_id' => $employee->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => now()->subDay(),
            'attendance_allowed' => true,
            'source' => 'MYSQL_TEST',
        ]);
        $device = new Device;
        $device->forceFill([
            'device_serial' => 'MYSQL-CONCURRENCY-DEVICE',
            'vending_machine_id' => $machine->id,
            'status' => DeviceStatus::ACTIVE,
            'is_active' => true,
            'credential_secret' => Str::random(64),
            'credential_version' => 1,
            'credential_issued_at' => now(),
        ])->save();
        $machine = $machine->fresh();

        return [$device, [
            'event_uuid' => (string) Str::uuid(),
            'employee_id' => (string) $employee->id,
            'event_type' => 'CHECK_IN',
            'captured_at' => now()->subMinute()->utc()->toIso8601String(),
            'employee_manifest_version' => $machine->employee_manifest_version,
            'configuration_version' => $machine->config_version,
            'assignment_uuid' => $assignment->uuid,
            'device_timezone' => $machine->timezone,
            'location' => null,
            'geofence' => null,
        ]];
    }

    private function runConcurrently(string $mode, Device $device, array $payload): array
    {
        $payloadPath = tempnam(sys_get_temp_dir(), 'vend-att-concurrency-');
        file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
        $startAt = microtime(true) + 0.75;
        $processes = [];
        $environment = $this->workerEnvironment();

        try {
            for ($index = 0; $index < 2; $index++) {
                $processes[] = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/phase4_mysql_concurrency_worker.php'),
                    $mode,
                    (string) $device->id,
                    $payloadPath,
                    (string) $startAt,
                ], base_path(), $environment, null, 30);
            }
            foreach ($processes as $process) {
                $process->start();
            }

            return array_map(function (Process $process): array {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

                return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }, $processes);
        } finally {
            @unlink($payloadPath);
        }
    }

    private function workerEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $this->mysql['host'],
            'DB_PORT' => (string) $this->mysql['port'],
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => (string) $this->mysql['username'],
            'DB_PASSWORD' => (string) $this->mysql['password'],
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'stderr',
        ];
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }
}
