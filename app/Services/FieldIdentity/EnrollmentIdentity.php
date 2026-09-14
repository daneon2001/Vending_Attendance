<?php

namespace App\Services\FieldIdentity;

use App\Enums\Employees\EmployeeSource;
use App\Models\Employee;
use App\Models\User;

/** Enrollment-only exception; never use this resolver for attendance/support. */
final class EnrollmentIdentity
{
    public function resolve(User $principal): ?Employee
    {
        $user = User::whereKey($principal->getKey())->where('estatus', true)->first();
        if (! $user || ! $user->employee_id) {
            return null;
        }
        $employee = $user->employee()->activeForVending()->first();
        if ($employee && $employee->source === EmployeeSource::FORTIA
            && trim((string) $employee->source_external_id) !== '') {
            return $employee;
        }

        return $employee && $this->isLocalDemo($user, $employee) ? $employee : null;
    }

    public function isLocalDemo(User $user, Employee $employee): bool
    {
        return app()->environment(['local', 'testing'])
            && $user->id === 4 && $user->email === 'pilot.support@example.test'
            && $user->estatus && $user->employee_id === 5
            && $employee->id === 5 && $employee->employee_number === '990001005'
            && $employee->source === EmployeeSource::DEMO && $employee->status === 'A';
    }
}
