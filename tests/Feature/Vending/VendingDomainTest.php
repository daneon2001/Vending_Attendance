<?php

namespace Tests\Feature\Vending;

use App\Enums\Vending\AssignmentStatus;
use App\Enums\Vending\AssignmentType;
use App\Enums\Vending\GeofenceStatus;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Employee;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use App\Services\Vending\MachineAssignmentService;
use App\Services\Vending\MachineGeofenceService;
use App\Services\Vending\VendingMachineImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendingDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_machine_is_an_independent_versioned_domain_entity(): void
    {
        $machine = $this->machine();

        $this->assertNotEmpty($machine->uuid);
        $this->assertSame(VendingMachineStatus::ACTIVE, $machine->status);
        $this->assertSame(1, $machine->config_version);
        $this->assertTrue($machine->hasVerifiedCoordinates());

        $machine->update(['latitude' => 19.4330]);
        $this->assertSame(2, $machine->refresh()->config_version);
    }

    public function test_machine_request_rejects_invalid_status_coordinates_and_zero_pair(): void
    {
        $this->withoutMiddleware()->postJson('/vending-machines', [
            'machine_code' => 'INVALID-01',
            'latitude' => 0,
            'longitude' => 0,
            'timezone' => 'America/Mexico_City',
            'status' => 'UNKNOWN',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'status']);

        $this->withoutMiddleware()->postJson('/vending-machines', [
            'machine_code' => 'INVALID-02',
            'latitude' => 91,
            'longitude' => -181,
            'timezone' => 'America/Mexico_City',
            'status' => 'ACTIVE',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_employee_and_machine_have_many_to_many_historical_assignments(): void
    {
        $employee = $this->employee();
        $machine = $this->machine();
        $service = app(MachineAssignmentService::class);

        $first = $service->create($machine, $this->assignmentData($employee->id));
        $second = $service->create($machine, $this->assignmentData($employee->id, [
            'assignment_type' => AssignmentType::TEMPORARY->value,
            'valid_from' => now()->addHour(),
            'valid_until' => now()->addDay(),
        ]));

        $this->assertCount(2, $machine->assignments()->get());
        $this->assertCount(2, $employee->machineAssignments()->get());
        $this->assertSame($machine->id, $first->vendingMachine->id);
        $this->assertNotSame($first->uuid, $second->uuid);
    }

    public function test_assignment_effectiveness_permissions_and_revocation_preserve_history(): void
    {
        $employee = $this->employee();
        $machine = $this->machine();
        $service = app(MachineAssignmentService::class);
        $assignment = $service->create($machine, $this->assignmentData($employee->id, [
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addHour(),
            'attendance_allowed' => true,
            'enrollment_allowed' => false,
        ]));

        $this->assertTrue($machine->assignments()->active()->effectiveAt(now())->attendanceAllowed()->exists());
        $this->assertFalse($machine->assignments()->active()->effectiveAt(now()->addDay())->exists());
        $this->assertFalse($machine->assignments()->active()->enrollmentAllowed()->exists());

        $service->revoke($assignment, null, 'Fin de cobertura');
        $this->assertSame(AssignmentStatus::REVOKED, $assignment->refresh()->status);
        $this->assertNotNull($assignment->revoked_at);
        $this->assertDatabaseHas('employee_machine_assignments', ['id' => $assignment->id, 'revocation_reason' => 'Fin de cobertura']);
    }

    public function test_geofences_are_versioned_and_activation_supersedes_previous_version(): void
    {
        $machine = $this->machine();
        $service = app(MachineGeofenceService::class);
        $first = $service->create($machine, $this->geofenceData('ACTIVE'));
        $second = $service->create($machine, $this->geofenceData('DRAFT', ['radius_m' => 60]));
        $service->activate($second);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(GeofenceStatus::SUPERSEDED, $first->refresh()->status);
        $this->assertSame(GeofenceStatus::ACTIVE, $second->refresh()->status);
        $this->assertSame(1, $machine->geofences()->active()->count());
        $this->assertSame(3, $machine->refresh()->config_version);
        $this->assertDatabaseHas('audit_logs', ['event' => 'geofence.activated', 'auditable_id' => $second->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'geofence.superseded', 'auditable_id' => $first->id]);
    }

    public function test_catalog_import_reports_outcomes_and_protects_verified_coordinates(): void
    {
        $service = app(VendingMachineImportService::class);
        $first = $service->import([
            ['sybi_id' => 'SYBI-10', 'machine_code' => 'IMP-10', 'latitude' => 19.4, 'longitude' => -99.1, 'postal_code' => '01234'],
            ['machine_code' => 'BAD-ZERO', 'latitude' => 0, 'longitude' => 0],
        ]);
        $machine = VendingMachine::query()->where('sybi_id', 'SYBI-10')->firstOrFail();
        $machine->update(['latitude' => 20.1, 'longitude' => -100.1, 'coordinate_source' => 'MANUAL', 'coordinates_verified' => true]);

        $second = $service->import([
            ['sybi_id' => 'SYBI-10', 'machine_code' => 'IMP-10', 'latitude' => 18.0, 'longitude' => -98.0, 'postal_code' => '01234', 'name' => 'Actualizada'],
        ]);

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['rejected']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame('20.1000000', $machine->refresh()->latitude);
        $this->assertSame('MANUAL', $machine->coordinate_source->value);
    }

    public function test_database_constraint_prevents_two_active_geofences(): void
    {
        $machine = $this->machine();
        MachineGeofence::query()->create(array_merge($this->geofenceData('ACTIVE'), [
            'vending_machine_id' => $machine->id,
            'version' => 1,
        ]));

        $this->expectException(QueryException::class);
        MachineGeofence::query()->create(array_merge($this->geofenceData('ACTIVE'), [
            'vending_machine_id' => $machine->id,
            'version' => 2,
        ]));
    }

    private function machine(array $attributes = []): VendingMachine
    {
        return VendingMachine::query()->create(array_merge([
            'machine_code' => 'VM-'.fake()->unique()->numerify('#####'),
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'coordinate_source' => 'MANUAL',
            'coordinates_verified' => true,
            'timezone' => 'America/Mexico_City',
            'status' => VendingMachineStatus::ACTIVE->value,
        ], $attributes));
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'fortia_employee_id' => fake()->unique()->numberBetween(100000, 999999),
            'full_name' => fake()->name(),
            'status' => 'A',
        ]);
    }

    private function assignmentData(int $employeeId, array $attributes = []): array
    {
        return array_merge([
            'employee_id' => $employeeId,
            'assignment_type' => AssignmentType::PRIMARY->value,
            'valid_from' => now()->subMinute(),
            'valid_until' => null,
            'attendance_allowed' => true,
            'enrollment_allowed' => false,
            'maintenance_allowed' => false,
        ], $attributes);
    }

    private function geofenceData(string $status, array $attributes = []): array
    {
        return array_merge([
            'center_latitude' => 19.4326,
            'center_longitude' => -99.1332,
            'radius_m' => 40,
            'minimum_acceptable_accuracy_m' => 25,
            'tolerance_m' => 2,
            'status' => $status,
            'source' => 'MANUAL',
        ], $attributes);
    }
}
