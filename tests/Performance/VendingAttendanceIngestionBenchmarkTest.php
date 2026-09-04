<?php

namespace Tests\Performance;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\VendingMachine;
use App\Services\Vending\MachineAssignmentService;
use App\Services\Vending\VendingAttendanceBatchReceiverService;
use App\Services\Vending\VendingAttendanceReceiverService;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class VendingAttendanceIngestionBenchmarkTest extends VendingDeviceApiTestCase
{
    public function test_controlled_synthetic_ingestion_profile(): void
    {
        $fixtures = $this->fixtures(100);
        $receiver = app(VendingAttendanceReceiverService::class);
        $batchReceiver = app(VendingAttendanceBatchReceiverService::class);
        $singlePayloads = [];
        $singleLatencies = [];
        $errors = 0;
        $singleStarted = microtime(true);

        foreach ($fixtures as $fixture) {
            for ($index = 0; $index < 100; $index++) {
                $payload = $this->attendancePayload($fixture['machine'], $fixture['employee'], $fixture['assignment']);
                $started = microtime(true);
                $result = $receiver->receive($fixture['device'], $payload);
                $singleLatencies[] = (microtime(true) - $started) * 1000;
                $singlePayloads[] = [$fixture['device'], $payload];
                $errors += $result['status'] === 'STORED' ? 0 : 1;
            }
        }
        $singleDuration = microtime(true) - $singleStarted;

        $duplicateLatencies = [];
        $duplicateStarted = microtime(true);
        foreach ($singlePayloads as [$device, $payload]) {
            $started = microtime(true);
            $result = $receiver->receive($device, $payload);
            $duplicateLatencies[] = (microtime(true) - $started) * 1000;
            $errors += $result['status'] === 'DUPLICATE' ? 0 : 1;
        }
        $duplicateDuration = microtime(true) - $duplicateStarted;

        $batchLatencies = [];
        $batchStarted = microtime(true);
        foreach ($fixtures as $fixture) {
            $events = [];
            for ($index = 0; $index < 100; $index++) {
                $events[] = $this->attendancePayload($fixture['machine'], $fixture['employee'], $fixture['assignment']);
            }
            $started = microtime(true);
            $result = $batchReceiver->receive($fixture['device'], ['events' => $events]);
            $batchLatencies[] = (microtime(true) - $started) * 1000;
            $errors += collect($result['results'])->where('status', '!=', 'STORED')->count();
        }
        $batchDuration = microtime(true) - $batchStarted;

        $report = [
            'environment' => 'PHPUnit SQLite in-memory; domain service; no HTTP/HMAC transport',
            'devices' => 100,
            'events_per_device' => 100,
            'single' => $this->measurement(10_000, $singleDuration, $singleLatencies),
            'batch' => $this->measurement(10_000, $batchDuration, $batchLatencies),
            'duplicate' => $this->measurement(10_000, $duplicateDuration, $duplicateLatencies),
            'errors' => $errors,
        ];

        fwrite(STDOUT, PHP_EOL.'PHASE4_BENCHMARK='.json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
        $this->assertSame(0, $errors);
        $this->assertDatabaseCount('vending_attendance_events', 20_000);
    }

    private function fixtures(int $count): array
    {
        $fixtures = [];
        for ($index = 1; $index <= $count; $index++) {
            $machine = VendingMachine::query()->create([
                'machine_code' => sprintf('BENCH-%04d', $index),
                'latitude' => 19.4326,
                'longitude' => -99.1332,
                'coordinate_source' => 'MANUAL',
                'coordinates_verified' => true,
                'timezone' => 'America/Mexico_City',
                'status' => 'ACTIVE',
            ]);
            $employee = Employee::query()->create([
                'fortia_employee_id' => 800000 + $index,
                'full_name' => 'Benchmark Employee '.$index,
                'status' => 'A',
            ]);
            $assignment = app(MachineAssignmentService::class)->create($machine, [
                'employee_id' => $employee->id,
                'assignment_type' => 'PRIMARY',
                'valid_from' => now()->subDay(),
                'attendance_allowed' => true,
                'source' => 'BENCHMARK',
            ]);
            $device = new Device;
            $device->forceFill([
                'device_serial' => sprintf('BENCH-DEVICE-%04d', $index),
                'vending_machine_id' => $machine->id,
                'status' => DeviceStatus::ACTIVE,
                'is_active' => true,
                'credential_secret' => Str::random(64),
                'credential_version' => 1,
                'credential_issued_at' => now(),
            ])->save();
            $fixtures[] = compact('machine', 'employee', 'assignment', 'device');
        }

        return $fixtures;
    }

    private function measurement(int $events, float $duration, array $latencies): array
    {
        sort($latencies, SORT_NUMERIC);

        return [
            'events' => $events,
            'duration_seconds' => round($duration, 3),
            'throughput_events_per_second' => round($events / max($duration, 0.000001), 2),
            'p50_ms' => round($this->percentile($latencies, 50), 3),
            'p95_ms' => round($this->percentile($latencies, 95), 3),
        ];
    }

    private function percentile(array $values, int $percentile): float
    {
        $index = max(0, min(count($values) - 1, (int) ceil(($percentile / 100) * count($values)) - 1));

        return (float) $values[$index];
    }
}
