<?php

namespace Tests\Feature\Vending;

use App\Models\DeviceManifestState;
use App\Models\EmployeeMachineAssignment;
use App\Services\Vending\DeviceFleetHealthService;
use App\Services\Vending\EmployeeManifestService;
use App\Services\Vending\MachineConfigurationManifestService;
use App\Services\Vending\VendingFleetOperationsService;
use Database\Seeders\VendingDemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class VendingDemoPreflightCommandTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-07 18:00:00', 'UTC'));
        config([
            'vending.device.health.degraded_after_seconds' => 180,
            'vending.device.health.offline_after_seconds' => 600,
            'vending.manifests.stale_after_minutes' => 10,
            'vending.fleet.alerts_limit' => 100,
        ]);
    }

    private function setupDemo(): array
    {
        $machine = $this->machine('VM-DEMO-001');
        $machine->update([
            'uuid' => VendingDemoSeeder::MACHINE_UUIDS['VM-DEMO-001'],
            'source' => 'DEMO',
            'metadata' => ['demo' => true, 'dataset' => 'VENDING_DEMO_V1'],
        ]);
        $geofence = $this->geofence($machine, ['source' => 'DEMO']);
        $employees = [];
        foreach (VendingDemoSeeder::EMPLOYEE_NUMBERS as $alias => $number) {
            $employees[$alias] = $this->employee([
                'employee_number' => (string) $number,
                'fortia_employee_id' => $number,
                'source' => 'DEMO',
                'source_external_id' => (string) $number,
                'company_name' => 'VENDING DEMO LOCAL',
            ]);
        }
        foreach (['VM1_PRIMARY' => 'DEMO1001', 'VM1_SUPERVISOR' => 'DEMO1004'] as $key => $alias) {
            $this->assignment($machine, $employees[$alias], [
                'uuid' => VendingDemoSeeder::ASSIGNMENT_UUIDS[$key],
                'assignment_type' => $key === 'VM1_PRIMARY' ? 'PRIMARY' : 'SUPERVISOR',
                'source' => 'DEMO',
            ]);
        }
        $provisioned = $this->provisionedDevice($machine, 'ANDROID-DEMO-001');
        $machine->refresh();
        $device = $provisioned['device'];
        $device->forceFill([
            'last_heartbeat_at' => now()->subSeconds(10),
            'last_seen_at' => now(),
            'network_state' => 'ONLINE',
            'pending_events_count' => 0,
            'config_version_applied' => $machine->config_version,
            'employee_manifest_version_applied' => $machine->employee_manifest_version,
        ])->save();
        foreach (['CONFIGURATION' => $machine->config_version, 'EMPLOYEES' => $machine->employee_manifest_version] as $type => $version) {
            DeviceManifestState::updateOrCreate([
                'device_id' => $device->id, 'manifest_type' => $type,
            ], [
                'applied_version' => $version, 'last_ack_version' => $version,
                'last_ack_status' => 'APPLIED', 'last_ack_at' => now(),
            ]);
        }

        return [$machine, $device, $geofence, $employees, $provisioned['credential']];
    }

    public function test_current_demo_scenario_passes_in_local_without_pilot_infrastructure(): void
    {
        $this->setupDemo();
        app()->detectEnvironment(fn () => 'local');
        config([
            'app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://127.0.0.1',
            'queue.default' => 'sync', 'cache.default' => 'array', 'session.secure' => false,
            'vending.pilot.scheduler_supervised' => false, 'logging.channels.single.level' => 'debug',
        ]);

        $this->artisan('vending:demo-preflight')
            ->expectsOutput('DEMO PREFLIGHT: PASS')->expectsOutput('READY_FOR_LIVE_DEMO')->assertSuccessful();
        $this->artisan('vending:pilot-preflight')->assertFailed();
    }

    #[DataProvider('unreadySignals')]
    public function test_real_missing_or_unready_evidence_fails_without_repair(string $case): void
    {
        [$machine, $device, $geofence, $employees] = $this->setupDemo();
        match ($case) {
            'offline heartbeat' => $device->update(['last_heartbeat_at' => now()->subSeconds(600)]),
            'delayed heartbeat' => $device->update(['last_heartbeat_at' => now()->subSeconds(180)]),
            'never heartbeat' => $device->update(['last_heartbeat_at' => null]),
            'future heartbeat' => $device->update(['last_heartbeat_at' => now()->addSecond()]),
            'network offline' => $device->update(['network_state' => 'OFFLINE']),
            'network unknown' => $device->update(['network_state' => null]),
            'pending outbox' => $device->update(['pending_events_count' => 1]),
            'unknown outbox' => $device->update(['pending_events_count' => null]),
            'inactive device' => $device->update(['status' => 'SUSPENDED']),
            'wrong machine' => $device->update(['vending_machine_id' => $this->machine('OTHER')->id]),
            'configuration pending' => $device->manifestStates()->where('manifest_type', 'CONFIGURATION')->update(['applied_version' => 0]),
            'employees pending' => $device->manifestStates()->where('manifest_type', 'EMPLOYEES')->update(['applied_version' => 0]),
            'ack failed' => $device->manifestStates()->where('manifest_type', 'EMPLOYEES')->update(['last_ack_status' => 'FAILED']),
            'manifest stale' => $device->update(['last_seen_at' => now()->subMinutes(11)]),
            'geofence expired' => $geofence->update(['valid_until' => now()->subSecond()]),
            'geofence not yet valid' => $geofence->update(['valid_from' => now()->addSecond()]),
            'geofence inactive' => $geofence->update(['status' => 'INACTIVE']),
            'geofence foreign' => $geofence->update(['source' => 'SYBI']),
            'geofence invalid' => $geofence->update(['center_latitude' => 0, 'center_longitude' => 0]),
            'geofence review' => $machine->update(['geofence_review_required' => true]),
            'machine inactive' => $machine->update(['status' => 'INACTIVE']),
            'machine maintenance' => $machine->update(['status' => 'MAINTENANCE']),
            'employee missing' => $employees['DEMO1005']->update(['source_external_id' => 'not-the-expected-demo']),
            'employee foreign' => $employees['DEMO1005']->update(['source' => 'FORTIA']),
            'employee inactive' => $employees['DEMO1001']->update(['status' => 'B']),
        };

        $this->artisan('vending:demo-preflight')
            ->expectsOutput('DEMO PREFLIGHT: FAIL')->expectsOutput('NOT_READY_FOR_LIVE_DEMO')->assertFailed();
    }

    public static function unreadySignals(): array
    {
        return array_map(fn ($case) => [$case], [
            'offline heartbeat', 'delayed heartbeat', 'never heartbeat', 'future heartbeat',
            'network offline', 'network unknown', 'pending outbox', 'unknown outbox',
            'inactive device', 'wrong machine', 'configuration pending', 'employees pending',
            'ack failed', 'manifest stale', 'geofence expired', 'geofence not yet valid',
            'geofence inactive', 'geofence foreign', 'geofence invalid', 'geofence review',
            'machine inactive', 'machine maintenance', 'employee missing', 'employee foreign', 'employee inactive',
        ]);
    }

    public function test_heartbeat_uses_existing_configured_threshold_not_a_new_constant(): void
    {
        [, $device] = $this->setupDemo();
        config(['vending.device.health.degraded_after_seconds' => 240]);
        $device->update(['last_heartbeat_at' => now()->subSeconds(239)]);
        $this->artisan('vending:demo-preflight')->assertSuccessful();
        $this->travel(1)->second();
        $this->artisan('vending:demo-preflight')->assertFailed();
    }

    #[DataProvider('assignmentFailures')]
    public function test_both_expected_demo_assignments_must_be_effective(string $case): void
    {
        [$machine, , , $employees] = $this->setupDemo();
        $assignment = EmployeeMachineAssignment::where('uuid', VendingDemoSeeder::ASSIGNMENT_UUIDS['VM1_SUPERVISOR'])->sole();
        match ($case) {
            'missing' => $assignment->delete(),
            'expired' => $assignment->update(['valid_until' => now()->subSecond()]),
            'future' => $assignment->update(['valid_from' => now()->addSecond()]),
            'revoked' => $assignment->revoke(),
            'permission missing' => $assignment->update(['attendance_allowed' => false]),
            'wrong employee' => $assignment->update(['employee_id' => $employees['DEMO1002']->id]),
            'wrong type' => $assignment->update(['assignment_type' => 'TEMPORARY']),
            'foreign source' => $assignment->update(['source' => 'MANUAL']),
        };
        // A substitute does not make the two expected identities valid.
        $this->assignment($machine, $employees['DEMO1003'], ['source' => 'DEMO']);

        $this->artisan('vending:demo-preflight')->assertFailed();
    }

    public static function assignmentFailures(): array
    {
        return array_map(fn ($case) => [$case], [
            'missing', 'expired', 'future', 'revoked', 'permission missing',
            'wrong employee', 'wrong type', 'foreign source',
        ]);
    }

    public function test_high_alert_anywhere_in_local_fleet_blocks_even_when_demo_device_is_online(): void
    {
        $this->setupDemo();
        $other = $this->provisionedDevice($this->machine('OTHER'), 'OTHER-OFFLINE');
        $this->assertSame('HIGH', app(VendingFleetOperationsService::class)->dashboard()['alerts'][0]['severity']);

        $this->artisan('vending:demo-preflight')->assertFailed();
        $this->assertNull($other['device']->fresh()->last_heartbeat_at);
    }

    public function test_only_medium_alerts_including_retired_device_do_not_block(): void
    {
        $this->setupDemo();
        $otherMachine = $this->machine('HISTORICAL');
        $retired = $this->provisionedDevice($otherMachine, 'HISTORICAL-RETIRED')['device'];
        $retired->update(['status' => 'RETIRED']);
        $otherMachine->update(['geofence_review_required' => true]);
        $alerts = app(VendingFleetOperationsService::class)->dashboard()['alerts'];
        $this->assertCount(2, $alerts);
        $this->assertContains('DEVICE_RETIRED', array_column($alerts, 'type'));
        $this->assertSame(['MEDIUM'], array_values(array_unique(array_column($alerts, 'severity'))));

        $this->artisan('vending:demo-preflight')->assertSuccessful();
    }

    public function test_missing_machine_and_device_fail_without_creating_them(): void
    {
        $this->artisan('vending:demo-preflight')->assertFailed();
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('vending_machines', 0);
        $this->machine('VM-DEMO-001');
        $this->artisan('vending:demo-preflight')->assertFailed();
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_does_not_certify_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['app.env' => 'production']);
        $this->artisan('vending:demo-preflight')->assertFailed();
    }

    public function test_unavailable_data_fails_closed_without_logging_or_printing_sensitive_details(): void
    {
        $this->setupDemo();
        $sensitive = 'test-only-exception-secret-19.4326123';
        $this->mock(DeviceFleetHealthService::class)
            ->shouldReceive('evaluate')->andThrow(new RuntimeException($sensitive));
        Log::spy();
        $this->assertSame(1, Artisan::call('vending:demo-preflight'));
        $output = Artisan::output();
        $this->assertStringNotContainsString($sensitive, $output);
        $this->assertStringContainsString('NOT_READY_FOR_LIVE_DEMO', $output);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    #[DataProvider('readOnlyScenarios')]
    public function test_command_executes_only_selects_and_preserves_every_table(bool $ready): void
    {
        [, $device, $geofence, , $credential] = $this->setupDemo();
        if (! $ready) {
            $device->update(['pending_events_count' => 2, 'last_heartbeat_at' => now()->subHour()]);
        }
        // These APIs can materialize hashes/versions and must never be invoked by preflight.
        $this->mock(EmployeeManifestService::class)->shouldNotReceive('snapshot');
        $this->mock(MachineConfigurationManifestService::class)->shouldNotReceive('snapshot');
        Http::preventStrayRequests();
        $before = $this->databaseSnapshot();
        $queries = [];
        $recording = true;
        DB::listen(function (QueryExecuted $event) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $event->sql; // No bindings, and never printed.
            }
        });
        try {
            $exit = Artisan::call('vending:demo-preflight');
            $output = Artisan::output();
        } finally {
            $recording = false;
        }
        $this->assertSame($ready ? 0 : 1, $exit);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertTrue((bool) preg_match('/^\s*select\b/i', $query), 'Preflight must issue SELECT queries only.');
        }
        $this->assertTrue($before === $this->databaseSnapshot(), 'Preflight changed database records.');
        foreach ([$credential, $device->getRawOriginal('credential_secret'), (string) config('app.key'),
            (string) $geofence->center_latitude, (string) $geofence->center_longitude] as $value) {
            if ($value !== '') {
                $this->assertStringNotContainsString($value, $output);
            }
        }
        Http::assertNothingSent();
    }

    public static function readOnlyScenarios(): array
    {
        return ['ready' => [true], 'not ready' => [false]];
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing() as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row))->all();
            sort($rows);
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }
}
