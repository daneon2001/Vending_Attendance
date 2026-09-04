<?php

namespace Tests\Feature\Vending;

use App\Enums\DeviceStatus;
use App\Enums\Vending\AssignmentStatus;
use App\Enums\Vending\GeofenceStatus;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Device;
use App\Models\EmployeeMachineAssignment;
use App\Models\MachineGeofence;
use App\Models\User;
use App\Models\VendingAttendanceEvent;
use App\Models\VendingMachine;
use Database\Seeders\VendingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class VendingDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-04 18:00:00', 'UTC'));
        config()->set('vending.demo.admin_password', Str::random(48));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_seeder_creates_the_controlled_demo_dataset_and_is_idempotent(): void
    {
        $this->seed(VendingDemoSeeder::class);

        $this->assertSame(3, VendingMachine::query()->whereIn('machine_code', array_keys(VendingDemoSeeder::MACHINE_UUIDS))->count());
        $this->assertSame(2, VendingMachine::query()->where('status', VendingMachineStatus::ACTIVE)->count());
        $this->assertSame(1, VendingMachine::query()->where('status', VendingMachineStatus::MAINTENANCE)->count());
        $this->assertDatabaseCount('employees', 5);
        $this->assertSame(6, EmployeeMachineAssignment::query()->whereIn('uuid', array_values(VendingDemoSeeder::ASSIGNMENT_UUIDS))->count());
        $this->assertSame(1, EmployeeMachineAssignment::query()->where('status', AssignmentStatus::REVOKED)->count());
        $this->assertSame(3, MachineGeofence::query()->whereIn('uuid', array_values(VendingDemoSeeder::GEOFENCE_UUIDS))->count());
        $this->assertSame(2, MachineGeofence::query()->where('status', GeofenceStatus::ACTIVE)->count());
        $this->assertSame(1, MachineGeofence::query()->where('status', GeofenceStatus::DRAFT)->count());
        $this->assertSame(2, Device::query()->whereIn('uuid', array_values(VendingDemoSeeder::DEVICE_UUIDS))->count());
        $this->assertSame(1, Device::query()->where('status', DeviceStatus::ACTIVE)->count());
        $this->assertSame(1, Device::query()->where('status', DeviceStatus::PENDING)->count());
        $this->assertSame(5, VendingAttendanceEvent::query()->whereIn('event_uuid', VendingDemoSeeder::EVENT_UUIDS)->count());
        $this->assertSame(3, VendingAttendanceEvent::query()->where('server_geofence_result', 'INSIDE')->count());
        $this->assertSame(1, VendingAttendanceEvent::query()->where('server_geofence_result', 'UNCERTAIN')->count());
        $this->assertSame(1, VendingAttendanceEvent::query()->where('server_geofence_result', 'OUTSIDE')->count());

        $administrator = User::query()->where('email', VendingDemoSeeder::ADMIN_EMAIL)->firstOrFail();
        $this->assertNotNull($administrator->email_verified_at);
        foreach (['view', 'create', 'update', 'assign', 'geofence', 'manage'] as $action) {
            $this->assertTrue($administrator->hasPermission('vending_machines', $action));
        }

        $versions = VendingMachine::query()
            ->whereIn('machine_code', array_keys(VendingDemoSeeder::MACHINE_UUIDS))
            ->orderBy('machine_code')
            ->get(['machine_code', 'config_version', 'employee_manifest_version'])
            ->mapWithKeys(fn (VendingMachine $machine): array => [$machine->machine_code => [
                $machine->config_version,
                $machine->employee_manifest_version,
            ]])
            ->all();

        $this->seed(VendingDemoSeeder::class);

        $this->assertSame(3, VendingMachine::query()->whereIn('machine_code', array_keys(VendingDemoSeeder::MACHINE_UUIDS))->count());
        $this->assertDatabaseCount('employees', 5);
        $this->assertSame(6, EmployeeMachineAssignment::query()->whereIn('uuid', array_values(VendingDemoSeeder::ASSIGNMENT_UUIDS))->count());
        $this->assertSame(3, MachineGeofence::query()->whereIn('uuid', array_values(VendingDemoSeeder::GEOFENCE_UUIDS))->count());
        $this->assertSame(2, Device::query()->whereIn('uuid', array_values(VendingDemoSeeder::DEVICE_UUIDS))->count());
        $this->assertSame(5, VendingAttendanceEvent::query()->whereIn('event_uuid', VendingDemoSeeder::EVENT_UUIDS)->count());
        $this->assertSame($versions, VendingMachine::query()
            ->whereIn('machine_code', array_keys(VendingDemoSeeder::MACHINE_UUIDS))
            ->orderBy('machine_code')
            ->get(['machine_code', 'config_version', 'employee_manifest_version'])
            ->mapWithKeys(fn (VendingMachine $machine): array => [$machine->machine_code => [
                $machine->config_version,
                $machine->employee_manifest_version,
            ]])
            ->all());
    }

    public function test_seeder_requires_an_explicit_password_when_no_vending_admin_exists(): void
    {
        config()->set('vending.demo.admin_password');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VENDING_DEMO_ADMIN_PASSWORD');

        $this->seed(VendingDemoSeeder::class);
    }

    public function test_seeder_refuses_to_run_in_production(): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');

        try {
            (new VendingDemoSeeder)->run();
            $this->fail('The production guard did not reject the seeder.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('restricted to local and testing', $exception->getMessage());
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_cleanup_command_removes_only_the_demo_dataset(): void
    {
        $this->seed(VendingDemoSeeder::class);
        $this->artisan('vending:demo-cleanup', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, VendingMachine::query()->whereIn('machine_code', array_keys(VendingDemoSeeder::MACHINE_UUIDS))->count());
        $this->assertSame(0, EmployeeMachineAssignment::query()->whereIn('uuid', array_values(VendingDemoSeeder::ASSIGNMENT_UUIDS))->count());
        $this->assertSame(0, MachineGeofence::query()->whereIn('uuid', array_values(VendingDemoSeeder::GEOFENCE_UUIDS))->count());
        $this->assertSame(0, Device::query()->whereIn('uuid', array_values(VendingDemoSeeder::DEVICE_UUIDS))->count());
        $this->assertSame(0, VendingAttendanceEvent::query()->whereIn('event_uuid', VendingDemoSeeder::EVENT_UUIDS)->count());
        $this->assertDatabaseMissing('users', ['email' => VendingDemoSeeder::ADMIN_EMAIL]);
    }

    public function test_demo_admin_can_search_filter_and_open_the_complete_machine_detail(): void
    {
        $this->seed(VendingDemoSeeder::class);
        $administrator = User::query()->where('email', VendingDemoSeeder::ADMIN_EMAIL)->firstOrFail();
        $machine = VendingMachine::query()->where('machine_code', 'VM-DEMO-001')->firstOrFail();

        $this->actingAs($administrator)
            ->get(route('vending-machines.index', ['search' => 'VM-DEMO-001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendingMachines/Index')
                ->has('machines.data', 1)
                ->where('machines.data.0.machine_code', 'VM-DEMO-001'));

        $this->actingAs($administrator)
            ->get(route('vending-machines.index', ['status' => VendingMachineStatus::MAINTENANCE->value]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('machines.data', 1)
                ->where('machines.data.0.machine_code', 'VM-DEMO-003'));

        $this->actingAs($administrator)
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendingMachines/Show')
                ->where('machine.machine_code', 'VM-DEMO-001')
                ->where('machine.address_line', 'Calle Demo 101')
                ->has('machine.geofences', 1)
                ->has('machine.assignments', 3)
                ->has('machine.devices', 1)
                ->where('machine.devices.0.manifest_sync.sync_state', 'SYNCED')
                ->has('auditLogs'));
    }
}
