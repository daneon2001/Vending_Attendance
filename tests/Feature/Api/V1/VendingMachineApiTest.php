<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Vending\AssignmentType;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\Vending\MachineGeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendingMachineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_boundary_requires_authentication(): void
    {
        $machine = $this->machine();

        $this->getJson("/api/v1/vending-machines/{$machine->uuid}")->assertUnauthorized();
        $this->postJson('/api/v1/geofence/validate', [])->assertUnauthorized();
    }

    public function test_authenticated_machine_and_active_geofence_lookup(): void
    {
        $this->authenticate();
        $machine = $this->machine();
        $geofence = app(MachineGeofenceService::class)->create($machine, $this->geofenceData());

        $this->getJson("/api/v1/vending-machines/{$machine->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $machine->uuid)
            ->assertJsonPath('data.active_geofence.version', $geofence->version);

        $this->getJson("/api/v1/vending-machines/{$machine->uuid}/geofence")
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_assignment_endpoints_expose_machine_employee_relationship(): void
    {
        $this->authenticate();
        $machine = $this->machine();
        $employee = Employee::query()->create(['fortia_employee_id' => 88001, 'full_name' => 'Persona Operadora']);
        EmployeeMachineAssignment::query()->create([
            'employee_id' => $employee->id,
            'vending_machine_id' => $machine->id,
            'assignment_type' => AssignmentType::PRIMARY->value,
            'valid_from' => now()->subMinute(),
            'attendance_allowed' => true,
            'enrollment_allowed' => false,
            'maintenance_allowed' => false,
        ]);

        $this->getJson("/api/v1/vending-machines/{$machine->uuid}/assignments")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.employee_number', '88001');
        $this->getJson("/api/v1/employees/{$employee->id}/vending-machines")
            ->assertOk()->assertJsonPath('data.0.machine.uuid', $machine->uuid);
    }

    public function test_geofence_validate_returns_structured_result_without_creating_attendance(): void
    {
        $this->authenticate();
        $machine = $this->machine();
        app(MachineGeofenceService::class)->create($machine, $this->geofenceData());

        $this->postJson('/api/v1/geofence/validate', [
            'machine_uuid' => $machine->uuid,
            'latitude' => 19.4327,
            'longitude' => -99.1332,
            'accuracy' => 3,
            'captured_at' => now()->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('machine_id', $machine->uuid)
            ->assertJsonPath('geofence_version', 1)
            ->assertJsonPath('result', 'INSIDE')
            ->assertJsonStructure(['distance_m', 'effective_distance_m', 'radius_m', 'accuracy_m', 'tolerance_m', 'reason']);

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_geofence_validate_rejects_zero_coordinates_and_missing_active_geofence(): void
    {
        $this->authenticate();
        $machine = $this->machine();

        $this->postJson('/api/v1/geofence/validate', ['machine_uuid' => $machine->uuid, 'latitude' => 0, 'longitude' => 0, 'accuracy' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->postJson('/api/v1/geofence/validate', ['machine_uuid' => $machine->uuid, 'latitude' => 19.4, 'longitude' => -99.1, 'accuracy' => 1])
            ->assertUnprocessable()->assertJsonPath('code', 'ACTIVE_GEOFENCE_NOT_FOUND');
    }

    private function machine(): VendingMachine
    {
        return VendingMachine::query()->create([
            'machine_code' => 'API-'.fake()->unique()->numerify('####'),
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'coordinate_source' => 'MANUAL',
            'coordinates_verified' => true,
            'timezone' => 'America/Mexico_City',
            'status' => VendingMachineStatus::ACTIVE->value,
        ]);
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'vending-api-'.fake()->unique()->numerify('####')]);
        $permission = Permission::query()->where('module', 'vending_machines')->where('action', 'view')->firstOrFail();
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function geofenceData(): array
    {
        return [
            'center_latitude' => 19.4326,
            'center_longitude' => -99.1332,
            'radius_m' => 40,
            'minimum_acceptable_accuracy_m' => 20,
            'tolerance_m' => 2,
            'status' => 'ACTIVE',
            'source' => 'MANUAL',
        ];
    }
}
