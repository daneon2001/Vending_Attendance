<?php

namespace Tests\Performance;

use App\Models\Device;
use App\Models\Employee;
use App\Models\VendingMachine;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class VendingFleetLoadBenchmarkTest extends VendingDeviceApiTestCase
{
    public function test_reconnect_storm_cohorts_on_disposable_mysql(): void
    {
        if (getenv('RUN_VENDING_RECONNECT_STORM') !== '1') {
            $this->markTestSkipped('Set RUN_VENDING_RECONNECT_STORM=1 explicitly.');
        }
        $this->assertSame('mysql', DB::getDriverName());
        $database = (string) DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/(?:^|_)testing(?:_|$)/', $database);
        config([
            'vending.device.rate_limits.heartbeat_per_minute' => 100000,
            'vending.manifests.rate_limits.status_per_minute' => 100000,
            'vending.attendance.rate_limits.batch_per_minute' => 100000,
        ]);

        $fixtures = $this->seedFleet(1000);
        $results = [];
        foreach ([100, 250, 500, 1000] as $size) {
            $results[(string) $size] = $this->reconnectCohort(array_slice($fixtures, 0, $size));
        }

        fwrite(STDERR, "\nRECONNECT_STORM_RESULT=".json_encode([
            'database' => $database,
            'execution_model' => 'single_php_worker_sequential_burst',
            'cohorts' => $results,
        ], JSON_UNESCAPED_SLASHES)."\n");
        $this->assertSame(0, array_sum(array_column($results, 'errors')));
    }

    public function test_one_thousand_device_edge_flow_on_disposable_mysql(): void
    {
        if (getenv('RUN_VENDING_FLEET_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set RUN_VENDING_FLEET_BENCHMARK=1 explicitly.');
        }
        $this->assertSame('mysql', DB::getDriverName());
        $database = (string) DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/(?:^|_)testing(?:_|$)/', $database);
        config([
            'vending.device.rate_limits.bootstrap_per_minute' => 100000,
            'vending.device.rate_limits.heartbeat_per_minute' => 100000,
            'vending.manifests.rate_limits.status_per_minute' => 100000,
            'vending.attendance.rate_limits.batch_per_minute' => 100000,
        ]);

        $fixtures = $this->seedFleet(1000);
        $countQueries = false;
        $queryCount = 0;
        DB::listen(function () use (&$countQueries, &$queryCount): void {
            if ($countQueries) {
                $queryCount++;
            }
        });
        $countQueries = true;
        $cpuBefore = getrusage();
        $started = hrtime(true);
        $errors = 0;
        $metrics = [];

        $metrics['heartbeat'] = $this->scenario($fixtures, function (array $fixture) use (&$errors): void {
            $response = $this->signedDeviceRequest('POST', '/api/v1/device/heartbeat', [
                'app_version' => '1.0.0', 'app_build_number' => 1,
                'platform_version' => '15', 'config_version_applied' => 1,
                'pending_events_count' => 0, 'network_state' => 'ONLINE',
                'device_time' => now()->utc()->toIso8601String(),
            ], $fixture['device'], $fixture['credential']);
            if ($response->status() !== 200) {
                $errors++;
            }
        }, $queryCount);
        $metrics['manifest_status'] = $this->scenario($fixtures, function (array $fixture) use (&$errors): void {
            $response = $this->signedDeviceRequest('GET', '/api/v1/device/manifests/status', [], $fixture['device'], $fixture['credential']);
            if ($response->status() !== 200) {
                $errors++;
            }
        }, $queryCount);
        $metrics['bootstrap'] = $this->scenario($fixtures, function (array $fixture) use (&$errors): void {
            $response = $this->signedDeviceRequest('GET', '/api/v1/device/bootstrap', ['config_version_applied' => 1], $fixture['device'], $fixture['credential']);
            if ($response->status() !== 200) {
                $errors++;
            }
        }, $queryCount);
        $metrics['attendance_batch'] = $this->scenario($fixtures, function (array $fixture) use (&$errors): void {
            $payload = [[
                'event_uuid' => (string) Str::uuid(),
                'employee_id' => (string) $fixture['employee_id'],
                'event_type' => 'CHECK_IN',
                'captured_at' => now()->utc()->toIso8601String(),
                'employee_manifest_version' => 1,
                'configuration_version' => 1,
                'assignment_uuid' => $fixture['assignment_uuid'],
                'device_timezone' => 'America/Mexico_City',
                'location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5],
                'geofence' => ['version' => 1, 'edge_result' => 'INSIDE'],
            ]];
            $response = $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events/batch', ['events' => $payload], $fixture['device'], $fixture['credential']);
            if ($response->status() !== 200 || $response->json('results.0.status') !== 'STORED') {
                $errors++;
            }
        }, $queryCount);

        $durationSeconds = (hrtime(true) - $started) / 1_000_000_000;
        $cpuAfter = getrusage();
        $threads = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
        $result = [
            'database' => $database,
            'devices' => count($fixtures),
            'requests' => count($fixtures) * count($metrics),
            'duration_seconds' => round($durationSeconds, 3),
            'throughput_rps' => round((count($fixtures) * count($metrics)) / $durationSeconds, 2),
            'scenarios' => $metrics,
            'errors' => $errors,
            'query_count' => $queryCount,
            'db_threads_connected' => isset($threads->Value) ? (int) $threads->Value : null,
            'explain_indexes' => [
                'health_scan' => $this->explainKey("SELECT id FROM devices WHERE status = 'ACTIVE' AND last_heartbeat_at < NOW() ORDER BY last_heartbeat_at LIMIT 100"),
                'version_distribution' => $this->explainKey("SELECT platform, app_version, COUNT(*) FROM devices WHERE platform = 'android' GROUP BY platform, app_version"),
                'attendance_by_device' => $this->explainKey('SELECT id FROM vending_attendance_events WHERE device_id = '.(int) $fixtures[0]['device']->id.' ORDER BY captured_at_device DESC LIMIT 20'),
            ],
            'cpu_seconds' => round($this->cpuSeconds($cpuBefore, $cpuAfter), 3),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];
        fwrite(STDERR, "\nFLEET_BENCHMARK_RESULT=".json_encode($result, JSON_UNESCAPED_SLASHES)."\n");
        $this->assertSame(0, $errors);
    }

    /** @return array<int, array<string, mixed>> */
    private function seedFleet(int $count): array
    {
        $now = now();
        $employee = Employee::query()->create([
            'fortia_employee_id' => 990001,
            'full_name' => 'Synthetic Fleet Employee',
            'status' => 'A',
        ]);
        $machineRows = [];
        foreach (range(1, $count) as $index) {
            $machineRows[] = [
                'uuid' => (string) Str::uuid(),
                'machine_code' => sprintf('LOAD-%04d', $index),
                'latitude' => 19.4326, 'longitude' => -99.1332,
                'coordinate_source' => 'MANUAL', 'coordinates_verified' => true,
                'timezone' => 'America/Mexico_City', 'status' => 'ACTIVE',
                'config_version' => 1, 'employee_manifest_version' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($machineRows, 200) as $chunk) {
            DB::table('vending_machines')->insert($chunk);
        }
        $machines = VendingMachine::query()->whereIn('uuid', array_column($machineRows, 'uuid'))->get()->keyBy('uuid');
        $deviceRows = $assignmentRows = $geofenceRows = [];
        $fixtureData = [];
        foreach ($machineRows as $index => $machineRow) {
            $machine = $machines->get($machineRow['uuid']);
            $deviceUuid = (string) Str::uuid();
            $assignmentUuid = (string) Str::uuid();
            $credential = hash('sha256', 'phase8-fixture-'.$deviceUuid);
            $deviceRows[] = [
                'uuid' => $deviceUuid, 'device_serial' => sprintf('LOAD-DEVICE-%04d', $index + 1),
                'platform' => 'android', 'platform_version' => '15', 'app_version' => '1.0.0',
                'app_build_number' => 1, 'release_channel' => 'PRODUCTION', 'status' => 'ACTIVE',
                'provisioned_at' => $now, 'activated_at' => $now,
                'credential_secret' => Crypt::encryptString($credential), 'credential_version' => 1,
                'credential_issued_at' => $now, 'vending_machine_id' => $machine->id,
                'active_vending_machine_id' => $machine->id, 'shared_secret' => '', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $assignmentRows[] = [
                'uuid' => $assignmentUuid, 'employee_id' => $employee->id,
                'vending_machine_id' => $machine->id, 'assignment_type' => 'PRIMARY',
                'valid_from' => $now->copy()->subDay(), 'valid_until' => null,
                'attendance_allowed' => true, 'enrollment_allowed' => false,
                'maintenance_allowed' => false, 'status' => 'ACTIVE', 'source' => 'LOAD_TEST',
                'created_at' => $now, 'updated_at' => $now,
            ];
            $geofenceRows[] = [
                'uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id,
                'version' => 1, 'shape' => 'CIRCLE', 'center_latitude' => 19.4326,
                'center_longitude' => -99.1332, 'radius_m' => 50,
                'minimum_acceptable_accuracy_m' => 30, 'tolerance_m' => 10,
                'status' => 'ACTIVE', 'source' => 'LOAD_TEST', 'active_machine_id' => $machine->id,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $fixtureData[$deviceUuid] = ['credential' => $credential, 'assignment_uuid' => $assignmentUuid];
        }
        foreach ([
            'devices' => $deviceRows,
            'employee_machine_assignments' => $assignmentRows,
            'machine_geofences' => $geofenceRows,
        ] as $table => $rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $devices = Device::query()->whereIn('uuid', array_keys($fixtureData))->get()->keyBy('uuid');

        return collect($fixtureData)->map(function (array $data, string $uuid) use ($devices, $employee): array {
            return array_merge($data, ['device' => $devices->get($uuid), 'employee_id' => $employee->id]);
        })->values()->all();
    }

    /** @param array<int, array<string, mixed>> $fixtures */
    private function reconnectCohort(array $fixtures): array
    {
        $latencies = [];
        $errors = 0;
        $queries = 0;
        $countQueries = false;
        DB::listen(function () use (&$countQueries, &$queries): void {
            if ($countQueries) {
                $queries++;
            }
        });
        $locksBefore = (int) (DB::selectOne("SHOW STATUS LIKE 'Innodb_row_lock_waits'")->Value ?? 0);
        $threadsBefore = (int) (DB::selectOne("SHOW STATUS LIKE 'Threads_connected'")->Value ?? 0);
        $countQueries = true;
        $cpuBefore = getrusage();
        $started = hrtime(true);

        foreach ($fixtures as $fixture) {
            $requests = [
                ['POST', '/api/v1/device/heartbeat', [
                    'app_version' => '1.0.0', 'app_build_number' => 1,
                    'platform_version' => '15', 'config_version_applied' => 1,
                    'pending_events_count' => 1, 'network_state' => 'ONLINE',
                    'device_time' => now()->utc()->toIso8601String(),
                ]],
                ['GET', '/api/v1/device/manifests/status', []],
                ['POST', '/api/v1/device/attendance/events/batch', ['events' => [[
                    'event_uuid' => (string) Str::uuid(),
                    'employee_id' => (string) $fixture['employee_id'],
                    'event_type' => 'CHECK_IN',
                    'captured_at' => now()->utc()->subMinutes(2)->toIso8601String(),
                    'employee_manifest_version' => 1,
                    'configuration_version' => 1,
                    'assignment_uuid' => $fixture['assignment_uuid'],
                    'device_timezone' => 'America/Mexico_City',
                    'location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5],
                    'geofence' => ['version' => 1, 'edge_result' => 'INSIDE'],
                ]]]],
            ];

            foreach ($requests as [$method, $path, $payload]) {
                $requestStarted = hrtime(true);
                $response = $this->signedDeviceRequest($method, $path, $payload, $fixture['device'], $fixture['credential']);
                $latencies[] = (hrtime(true) - $requestStarted) / 1_000_000;
                if ($response->status() !== 200) {
                    $errors++;
                }
            }
        }

        $duration = (hrtime(true) - $started) / 1_000_000_000;
        $cpuAfter = getrusage();
        sort($latencies);
        $countQueries = false;
        $locksAfter = (int) (DB::selectOne("SHOW STATUS LIKE 'Innodb_row_lock_waits'")->Value ?? 0);
        $threadsAfter = (int) (DB::selectOne("SHOW STATUS LIKE 'Threads_connected'")->Value ?? 0);

        return [
            'devices' => count($fixtures),
            'requests' => count($latencies),
            'window_seconds' => round($duration, 3),
            'throughput_rps' => round(count($latencies) / $duration, 2),
            'p50_ms' => round($this->percentile($latencies, 50), 2),
            'p95_ms' => round($this->percentile($latencies, 95), 2),
            'p99_ms' => round($this->percentile($latencies, 99), 2),
            'errors' => $errors,
            'query_count' => $queries,
            'queries_per_request' => round($queries / count($latencies), 2),
            'db_connections_before' => $threadsBefore,
            'db_connections_after' => $threadsAfter,
            'db_lock_waits_delta' => max(0, $locksAfter - $locksBefore),
            'cpu_seconds' => round($this->cpuSeconds($cpuBefore, $cpuAfter), 3),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];
    }

    /** @param callable(array<string,mixed>):void $callback */
    private function scenario(array $fixtures, callable $callback, int &$queryCount): array
    {
        $latencies = [];
        $started = hrtime(true);
        $queriesBefore = $queryCount;
        foreach ($fixtures as $fixture) {
            $requestStarted = hrtime(true);
            $callback($fixture);
            $latencies[] = (hrtime(true) - $requestStarted) / 1_000_000;
        }
        sort($latencies);
        $duration = (hrtime(true) - $started) / 1_000_000_000;

        return [
            'rps' => round(count($fixtures) / $duration, 2),
            'p50_ms' => round($this->percentile($latencies, 50), 2),
            'p95_ms' => round($this->percentile($latencies, 95), 2),
            'p99_ms' => round($this->percentile($latencies, 99), 2),
            'query_count' => $queryCount - $queriesBefore,
            'queries_per_request' => round(($queryCount - $queriesBefore) / count($fixtures), 2),
        ];
    }

    private function percentile(array $values, int $percentile): float
    {
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return (float) $values[max(0, $index)];
    }

    private function cpuSeconds(array $before, array $after): float
    {
        $user = (($after['ru_utime.tv_sec'] ?? 0) - ($before['ru_utime.tv_sec'] ?? 0))
            + ((($after['ru_utime.tv_usec'] ?? 0) - ($before['ru_utime.tv_usec'] ?? 0)) / 1_000_000);
        $system = (($after['ru_stime.tv_sec'] ?? 0) - ($before['ru_stime.tv_sec'] ?? 0))
            + ((($after['ru_stime.tv_usec'] ?? 0) - ($before['ru_stime.tv_usec'] ?? 0)) / 1_000_000);

        return $user + $system;
    }

    private function explainKey(string $sql): ?string
    {
        $row = DB::selectOne('EXPLAIN '.$sql);

        return isset($row->key) ? (string) $row->key : null;
    }
}
