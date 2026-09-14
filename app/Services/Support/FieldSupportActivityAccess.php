<?php

namespace App\Services\Support;

use App\Enums\Employees\EmployeeSource;
use App\Enums\Support\SupportActivityType;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Database\Eloquent\Builder;

/** Explicitly constructed by the native Field Support adapter; never a global binding. */
class FieldSupportActivityAccess extends SupportActivityAccess
{
    public function identity(): array
    {
        $user = User::query()->whereKey(auth()->id())->lockForUpdate()->first();
        abort_unless($user && $user->estatus && $user->employee_id, 403);
        $employee = Employee::query()->whereKey($user->employee_id)->activeForVending()->lockForUpdate()->first();
        abort_unless($employee && (User::authenticatedEmployee()?->id === $employee->id
            || $this->demoEmployee($user, $employee)), 403);
        $this->device($user, $employee);

        return [$user, $employee];
    }

    public function device(User $user, Employee $employee): EmployeeDevice
    {
        $device = EmployeeDevice::query()->where('user_id', $user->id)->where('employee_id', $employee->id)
            ->where('active_employee_id', $employee->id)->where('status', 'ACTIVE')
            ->whereNotNull('verified_at')->whereNull('ended_at')->lockForUpdate()->first();
        abort_unless($device, 403);

        return $device;
    }

    private function demoEmployee(User $user, Employee $employee): bool
    {
        return app()->environment(['local', 'testing']) && (int) $user->id === 4
            && $user->email === 'pilot.support@example.test' && (int) $employee->id === 5
            && $employee->employee_number === '990001005' && $employee->source === EmployeeSource::DEMO;
    }

    protected function eligibleEmployee(Employee $employee, VendingMachine $machine, ?SupportActivityType $type): bool
    {
        [$user, $own] = $this->identity();
        if ((int) $own->id !== (int) $employee->id) {
            return false;
        }
        if ($employee->source !== EmployeeSource::DEMO) {
            return parent::eligibleEmployee($employee, $machine, $type);
        }

        return $this->demoEmployee($user, $employee) && $machine->machine_code === 'VM-DEMO-001'
            && ($machine->getRawOriginal('source') === 'DEMO')
            && ($type === null || in_array($type->value, ['MAINTENANCE', 'REPAIR', 'COMPONENT_REPLACEMENT'], true));
    }

    public function visible(User $user, Employee $employee): Builder
    {
        $query = parent::visible($user, $employee)->where('employee_id', $employee->id);
        if ($employee->source === EmployeeSource::DEMO) {
            $query->whereHas('vendingMachine', fn ($q) => $q->where('machine_code', 'VM-DEMO-001')->where('source', 'DEMO'))
                ->whereIn('activity_type', ['MAINTENANCE', 'REPAIR', 'COMPONENT_REPLACEMENT']);
        }

        return $query;
    }

    /** Read-only projection using the same identity, active-device and object restrictions. */
    public function notificationScope(User $user): Builder
    {
        $empty = \App\Models\VendingSupportActivity::query()->whereRaw('1 = 0');
        $employee = $user->employee()->activeForVending()->first();
        if (! $user->estatus || ! $user->hasPermission('support', 'view') || ! $employee
            || ! (($employee->source === EmployeeSource::FORTIA && trim((string) $employee->source_external_id) !== '')
                || $this->demoEmployee($user, $employee))) {
            return $empty;
        }
        if (! EmployeeDevice::query()->where('user_id', $user->id)->where('employee_id', $employee->id)
            ->where('active_employee_id', $employee->id)->where('status', 'ACTIVE')
            ->whereNotNull('verified_at')->whereNull('ended_at')->exists()) {
            return $empty;
        }

        return $this->visible($user, $employee);
    }
}
