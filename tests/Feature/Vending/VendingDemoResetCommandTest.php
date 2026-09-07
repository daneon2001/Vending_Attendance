<?php

namespace Tests\Feature\Vending;

use App\Enums\Employees\EmployeeSource;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Services\Vending\EmployeeManifestService;
use App\Services\Vending\MachineConfigurationManifestService;
use Database\Seeders\VendingDemoSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class VendingDemoResetCommandTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 6)->setTime(18, 0));
    }

    private function setupDemo(): array
    {
        $machine = $this->machine('VM-DEMO-001');
        $machine->update([
            'uuid' => VendingDemoSeeder::MACHINE_UUIDS['VM-DEMO-001'],
            'source' => 'LOCAL',
            'metadata' => ['demo' => true, 'dataset' => 'VENDING_DEMO_V1'],
        ]);
        $geofence = $this->geofence($machine, ['source' => 'DEMO']);
        $provisioned = $this->provisionedDevice($machine, 'ANDROID-DEMO-001');

        return [$machine->fresh(), $provisioned['device']->fresh(), $geofence, $provisioned['credential']];
    }

    private function snapshot(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function test_reset_is_idempotent_creates_employees_once_and_keeps_assignments_versions_and_device_stable(): void
    {
        [$machine, $device, $geofence] = $this->setupDemo();
        $originalDevice = $device->getRawOriginal();
        $originalGeofence = $geofence->getRawOriginal();
        $this->artisan('vending:demo-reset')->expectsOutput('DEMO RESET: PASS')->assertSuccessful();

        $this->assertDatabaseCount('employees', 5);
        $this->assertSame(5, Employee::where('source', 'DEMO')->count());
        $this->assertDatabaseCount('employee_machine_assignments', 2);
        $this->assertDatabaseCount('vending_attendance_events', 0);
        $this->assertSame($originalDevice, $device->fresh()->getRawOriginal());
        $this->assertSame($originalGeofence, $geofence->fresh()->getRawOriginal());
        $before = [];
        foreach (['employees', 'employee_machine_assignments', 'vending_machines', 'devices', 'device_manifest_states', 'audit_logs'] as $table) {
            $before[$table] = $this->snapshot($table);
        }

        $manifest = app(EmployeeManifestService::class)->snapshot($machine->fresh());
        $configuration = app(MachineConfigurationManifestService::class)->snapshot($device->fresh());
        $this->assertCount(2, $manifest['employees']);
        $this->assertSame($geofence->uuid, $configuration['geofence']['uuid']);
        $this->travel(1)->hour();
        $this->artisan('vending:demo-reset')->assertSuccessful();

        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $this->snapshot($table), $table);
        }
        $secondManifest = app(EmployeeManifestService::class)->snapshot($machine->fresh());
        $this->assertSame($manifest['manifest_hash'], $secondManifest['manifest_hash']);
        $this->assertSame($manifest['manifest_version'], $secondManifest['manifest_version']);
    }

    public function test_local_environment_is_allowed_without_cli_password_or_override(): void
    {
        $this->setupDemo();
        $original = Env::get('APP_ENV');
        app()->detectEnvironment(fn () => 'local');
        config(['app.env' => 'local']);
        $this->setEnvironment('local');
        try {
            $this->artisan('vending:demo-reset')->assertSuccessful();
        } finally {
            $this->setEnvironment($original);
        }
    }

    #[DataProvider('forbiddenEnvironments')]
    public function test_forbidden_environments_fail_closed(string $runtime, string $configured, string $external): void
    {
        $this->setupDemo();
        $before = $this->snapshot('vending_machines');
        $original = Env::get('APP_ENV');
        app()->detectEnvironment(fn () => $runtime);
        config(['app.env' => $configured]);
        $this->setEnvironment($external);
        try {
            $this->artisan('vending:demo-reset')->assertFailed();
            $this->assertDatabaseCount('employees', 0);
            $this->assertSame($before, $this->snapshot('vending_machines'));
        } finally {
            $this->setEnvironment($original);
        }
    }

    public static function forbiddenEnvironments(): array
    {
        return [
            'production' => ['production', 'production', 'production'],
            'runtime override' => ['local', 'production', 'production'],
            'cached local' => ['local', 'local', 'production'],
            'staging' => ['staging', 'staging', 'staging'],
        ];
    }

    private function setEnvironment(string $environment): void
    {
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;
        putenv('APP_ENV='.$environment);
        $this->assertSame($environment, Env::get('APP_ENV'));
    }

    public function test_missing_device_never_creates_credentials_or_fake_telemetry(): void
    {
        $this->artisan('vending:demo-reset')->assertFailed();
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_legacy_synthetic_employees_are_reused_without_changing_their_numbers(): void
    {
        $this->setupDemo();
        $employee = $this->employee([
            'fortia_employee_id' => 990001001,
            'employee_number' => '990001001',
            'full_name' => 'Empleado Demo Uno',
            'name' => 'Empleado Demo',
            'last_name' => 'Uno',
            'source' => 'LEGACY',
            'company_name' => 'VENDING DEMO LOCAL',
        ]);
        $this->artisan('vending:demo-reset')->assertSuccessful();
        $this->assertDatabaseCount('employees', 5);
        $this->assertSame(EmployeeSource::DEMO, $employee->fresh()->source);
        $this->assertSame('990001001', $employee->fresh()->employee_number);
        $this->assertSame(990001001, (int) $employee->fresh()->fortia_employee_id);
    }

    public function test_scope_is_transactional_and_refuses_a_real_employee_collision(): void
    {
        $this->setupDemo();
        $employee = $this->employee(['employee_number' => 'DEMO1005', 'source' => 'FORTIA']);
        $before = $employee->fresh()->getRawOriginal();
        $audits = $this->snapshot('audit_logs');
        $this->artisan('vending:demo-reset')->assertFailed();
        $this->assertDatabaseCount('employees', 1); // Earlier four creations rolled back.
        $this->assertDatabaseCount('employee_machine_assignments', 0);
        $this->assertSame($before, $employee->fresh()->getRawOriginal());
        $this->assertSame($audits, $this->snapshot('audit_logs'));
    }

    public function test_sybi_machine_is_not_claimed_as_demo_even_with_matching_code_and_metadata(): void
    {
        [$machine] = $this->setupDemo();
        $machine->update(['source' => 'SYBI', 'sybi_id' => 42]);
        $before = $machine->fresh()->getRawOriginal();
        $this->artisan('vending:demo-reset')->assertFailed();
        $this->assertSame($before, $machine->fresh()->getRawOriginal());
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_non_demo_links_prevent_adoption_and_all_changes_roll_back(): void
    {
        [$machine] = $this->setupDemo();
        $this->artisan('vending:demo-reset')->assertSuccessful();
        $other = $this->machine('REAL-OPERATION');
        $employee = Employee::where('employee_number', 'DEMO1005')->sole();
        $this->assignment($other, $employee);
        $machine->update(['status' => 'MAINTENANCE']);
        $before = $this->snapshot('employees');
        $this->artisan('vending:demo-reset')->assertFailed();
        $this->assertSame('MAINTENANCE', $machine->fresh()->status->value);
        $this->assertSame($before, $this->snapshot('employees'));
    }

    public function test_existing_demo_and_non_demo_attendance_audit_users_and_telemetry_are_preserved(): void
    {
        [$machine, $device, $geofence, $credential] = $this->setupDemo();
        $this->artisan('vending:demo-reset')->assertSuccessful();
        $employee = Employee::where('employee_number', 'DEMO1001')->sole();
        $assignment = EmployeeMachineAssignment::where('employee_id', $employee->id)->sole();
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events',
            $this->attendancePayload($machine, $employee, $assignment, $geofence), $device, $credential)
            ->assertCreated();

        $other = $this->machine('UNRELATED-MACHINE');
        $otherEmployee = $this->employee(['source' => 'FORTIA']);
        $otherAssignment = $this->assignment($other, $otherEmployee);
        $otherFence = $this->geofence($other);
        $otherDevice = $this->provisionedDevice($other);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events',
            $this->attendancePayload($other, $otherEmployee, $otherAssignment, $otherFence),
            $otherDevice['device'], $otherDevice['credential'])->assertCreated();
        \App\Models\User::factory()->create(['email' => 'unrelated@example.test']);
        $tables = ['users', 'roles', 'permissions', 'role_user', 'permission_role',
            'vending_attendance_events', 'audit_logs', 'devices', 'device_manifest_states',
            'device_attendance_metrics', 'device_provisioning_tokens', 'employees'];
        $before = [];
        foreach ($tables as $table) {
            // Pivots have no id.
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->travel(2)->hours();
        $this->artisan('vending:demo-reset')->assertSuccessful();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
    }

    public function test_pending_events_are_not_zeroed_and_a_non_active_device_is_not_reactivated(): void
    {
        [, $device] = $this->setupDemo();
        $device->forceFill(['pending_events_count' => 3])->save();
        $this->artisan('vending:demo-reset')->assertFailed();
        $this->assertSame(3, (int) $device->fresh()->pending_events_count);
        $device->forceFill(['pending_events_count' => 0, 'status' => 'SUSPENDED'])->save();
        $this->artisan('vending:demo-reset')->assertFailed();
        $this->assertSame('SUSPENDED', $device->fresh()->status->value);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_revoked_assignment_is_not_resurrected_and_geofence_is_not_moved(): void
    {
        [, , $geofence] = $this->setupDemo();
        $this->artisan('vending:demo-reset')->assertSuccessful();
        $assignment = EmployeeMachineAssignment::first();
        $assignment->revoke();
        $before = $geofence->fresh()->getRawOriginal();
        $this->artisan('vending:demo-reset')->assertFailed();
        $this->assertSame('REVOKED', $assignment->fresh()->status->value);
        $this->assertSame($before, $geofence->fresh()->getRawOriginal());
    }

    public function test_console_never_prints_credentials_or_fabricates_readiness(): void
    {
        [, $device, , $credential] = $this->setupDemo();
        $states = $this->snapshot('device_manifest_states');
        $this->assertSame(0, Artisan::call('vending:demo-reset'));
        $output = Artisan::output();
        $this->assertStringNotContainsString($credential, $output);
        $this->assertStringNotContainsString($device->getRawOriginal('credential_secret'), $output);
        $this->assertStringContainsString('ACTIVE / OFFLINE', $output);
        $this->assertStringContainsString('PENDING LAST REPORTED: UNKNOWN', $output);
        $this->assertStringContainsString('NOT_READY_UNTIL_PHYSICAL_VALIDATION', $output);
        $this->assertSame($states, $this->snapshot('device_manifest_states'));
    }

    #[DataProvider('unsafePrerequisites')]
    public function test_unsafe_prerequisites_fail_without_any_employee_writes(string $case): void
    {
        [$machine, $device, $geofence] = $this->setupDemo();
        match ($case) {
            'unmarked machine' => $machine->update(['metadata' => []]),
            'foreign device' => $device->forceFill(['vending_machine_id' => $this->machine('OTHER')->id])->save(),
            'revoked credential' => $device->forceFill(['credential_revoked_at' => now()])->save(),
            'expired geofence' => $geofence->update(['valid_until' => now()->subMinute()]),
            'foreign geofence' => $geofence->update(['source' => 'SYBI']),
            'invalid geofence' => $geofence->update(['center_latitude' => 0, 'center_longitude' => 0]),
        };
        $before = $this->snapshot('audit_logs');
        $this->artisan('vending:demo-reset')->assertFailed();
        $this->assertDatabaseCount('employees', 0);
        $this->assertSame($before, $this->snapshot('audit_logs'));
    }

    public static function unsafePrerequisites(): array
    {
        return array_map(fn ($case) => [$case], [
            'unmarked machine', 'foreign device', 'revoked credential',
            'expired geofence', 'foreign geofence', 'invalid geofence',
        ]);
    }

    public function test_reset_restores_demo_operational_status_without_touching_coordinates_or_device(): void
    {
        [$machine, $device] = $this->setupDemo();
        $this->artisan('vending:demo-reset')->assertSuccessful();
        $machine->update(['status' => 'MAINTENANCE']);
        $beforeVersion = $machine->fresh()->config_version;
        $beforeDevice = $device->fresh()->getRawOriginal();
        $this->artisan('vending:demo-reset')->assertSuccessful();
        $this->assertSame('ACTIVE', $machine->fresh()->status->value);
        $this->assertSame($beforeVersion + 1, $machine->fresh()->config_version);
        $this->assertSame($machine->latitude, $machine->fresh()->latitude);
        $this->assertSame($machine->longitude, $machine->fresh()->longitude);
        $this->assertSame($beforeDevice, $device->fresh()->getRawOriginal());
    }
}
