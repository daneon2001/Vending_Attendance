<?php

namespace App\Services\Vending;

use App\Enums\Vending\VendingMachineStatus;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;
use DateTimeInterface;

class MachineAuthorizationService
{
    public function canAttend(Employee $employee, VendingMachine $machine, DateTimeInterface|string|null $timestamp = null): bool
    {
        return $this->hasPermission($employee, $machine, 'attendance_allowed', $timestamp);
    }

    public function canEnroll(Employee $employee, VendingMachine $machine, DateTimeInterface|string|null $timestamp = null): bool
    {
        return $this->hasPermission($employee, $machine, 'enrollment_allowed', $timestamp);
    }

    public function canMaintain(Employee $employee, VendingMachine $machine, DateTimeInterface|string|null $timestamp = null): bool
    {
        return $this->hasPermission($employee, $machine, 'maintenance_allowed', $timestamp);
    }

    private function hasPermission(
        Employee $employee,
        VendingMachine $machine,
        string $permission,
        DateTimeInterface|string|null $timestamp,
    ): bool {
        $status = $machine->status instanceof VendingMachineStatus
            ? $machine->status
            : VendingMachineStatus::tryFrom((string) $machine->status);

        if ($status !== VendingMachineStatus::ACTIVE) {
            return false;
        }

        return EmployeeMachineAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('vending_machine_id', $machine->getKey())
            ->active()
            ->effectiveAt($timestamp)
            ->where($permission, true)
            ->exists();
    }
}
