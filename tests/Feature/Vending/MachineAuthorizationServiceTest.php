<?php

namespace Tests\Feature\Vending;

use App\Enums\Vending\AssignmentStatus;
use App\Enums\Vending\AssignmentType;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;
use App\Services\Vending\MachineAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineAuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_explicit_and_not_inferred_from_assignment_type(): void
    {
        [$employee, $machine] = $this->entities();
        $this->assignment($employee, $machine, [
            'assignment_type' => AssignmentType::TECHNICIAN->value,
            'attendance_allowed' => true,
            'enrollment_allowed' => true,
            'maintenance_allowed' => false,
        ]);
        $service = app(MachineAuthorizationService::class);

        $this->assertTrue($service->canAttend($employee, $machine));
        $this->assertTrue($service->canEnroll($employee, $machine));
        $this->assertFalse($service->canMaintain($employee, $machine));
    }

    public function test_expired_future_revoked_and_inactive_machine_assignments_are_denied(): void
    {
        [$employee, $machine] = $this->entities();
        $expired = $this->assignment($employee, $machine, ['valid_from' => now()->subDays(2), 'valid_until' => now()->subDay()]);
        $future = $this->assignment($employee, $machine, ['valid_from' => now()->addDay()]);
        $revoked = $this->assignment($employee, $machine, ['status' => AssignmentStatus::REVOKED->value, 'revoked_at' => now()]);
        $service = app(MachineAuthorizationService::class);

        $this->assertFalse($service->canAttend($employee, $machine));
        $this->assertDatabaseHas('employee_machine_assignments', ['id' => $expired->id]);
        $this->assertDatabaseHas('employee_machine_assignments', ['id' => $future->id]);
        $this->assertDatabaseHas('employee_machine_assignments', ['id' => $revoked->id]);

        $this->assignment($employee, $machine);
        $machine->update(['status' => VendingMachineStatus::INACTIVE->value]);
        $this->assertFalse($service->canAttend($employee, $machine->refresh()));
    }

    private function entities(): array
    {
        $employee = Employee::query()->create(['fortia_employee_id' => fake()->unique()->numberBetween(100000, 999999), 'full_name' => 'Operador Uno']);
        $machine = VendingMachine::query()->create(['machine_code' => 'AUTH-'.fake()->unique()->numerify('####'), 'latitude' => 19.4, 'longitude' => -99.1, 'timezone' => 'America/Mexico_City', 'status' => VendingMachineStatus::ACTIVE->value]);

        return [$employee, $machine];
    }

    private function assignment(Employee $employee, VendingMachine $machine, array $attributes = []): EmployeeMachineAssignment
    {
        return EmployeeMachineAssignment::query()->create(array_merge([
            'employee_id' => $employee->id,
            'vending_machine_id' => $machine->id,
            'assignment_type' => AssignmentType::PRIMARY->value,
            'valid_from' => now()->subHour(),
            'attendance_allowed' => true,
            'enrollment_allowed' => false,
            'maintenance_allowed' => false,
            'status' => AssignmentStatus::ACTIVE->value,
        ], $attributes));
    }
}
