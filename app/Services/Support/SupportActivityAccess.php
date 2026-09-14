<?php

namespace App\Services\Support;

use App\Enums\Employees\EmployeeSource;
use App\Enums\Support\SupportActivityType;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\User;
use App\Models\VendingMachine;
use App\Models\VendingSupportActivity;
use App\Services\Vending\MachineAuthorizationService;
use Illuminate\Database\Eloquent\Builder;

class SupportActivityAccess
{
    public function identity(): array
    {
        $employee = User::authenticatedEmployee();
        abort_unless($employee !== null, 403, 'Necesitas una cuenta activa vinculada a tu identidad laboral Fortia.');
        $user = User::query()->findOrFail(auth()->id());

        return [$user, $employee];
    }

    public function permission(User $user, string $permission): void
    {
        abort_unless($user->hasPermission('support', $permission), 403, 'No tienes permiso para esta actividad.');
    }

    public function machine(Employee $employee, VendingMachine $machine, ?SupportActivityType $type = null): void
    {
        abort_unless($this->eligibleEmployee($employee, $machine, $type), 403);
        abort_unless($machine->status === VendingMachineStatus::ACTIVE
            && ! app(SupportAccess::class)->reservedForFuturePhysicalTest($machine), 403, 'Máquina no disponible para actividades.');
        abort_unless(EmployeeMachineAssignment::query()->where('employee_id', $employee->id)
            ->where('vending_machine_id', $machine->id)->active()->effectiveAt()->exists(), 403,
            'No tienes una asignación vigente para esta máquina.');
        if ($type?->requiresMaintenance()) {
            abort_unless(app(MachineAuthorizationService::class)->canMaintain($employee, $machine), 403,
                'La asignación no permite mantenimiento.');
        }
    }

    public function execute(VendingSupportActivity $activity, User $user, Employee $employee, VendingMachine $machine): void
    {
        abort_unless((int) $activity->employee_id === (int) $employee->id, 404, 'Actividad no disponible.');
        $this->permission($user, $activity->activity_type->permission());
        $this->machine($employee, $machine, $activity->activity_type);
    }

    protected function eligibleEmployee(Employee $employee, VendingMachine $machine, ?SupportActivityType $type): bool
    {
        return $employee->source === EmployeeSource::FORTIA
            && trim((string) $employee->source_external_id) !== ''
            && Employee::query()->whereKey($employee->id)->activeForVending()->exists();
    }

    public function visible(User $user, Employee $employee): Builder
    {
        $this->permission($user, 'view');
        $query = VendingSupportActivity::query()
            ->whereIn('vending_machine_id', EmployeeMachineAssignment::query()
                ->select('vending_machine_id')->where('employee_id', $employee->id)->active()->effectiveAt());
        if (! $user->hasPermission('support', 'assign')) {
            $query->where('employee_id', $employee->id);
        }

        return $query;
    }
}
