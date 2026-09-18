<?php

namespace App\Services\FieldIdentity;

use App\Enums\Employees\EmployeeSource;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Eligibility only: never grants roles, assignment capabilities or attendance. */
class BetaTesterPolicy
{
    public function entry(User $user, Employee $employee): ?array
    {
        if (! \App\Support\InternalBeta::simulationAllowed()) {
            return null;
        }
        $current = User::whereKey($user->id)->where('estatus', true)->first();
        $person = Employee::whereKey($employee->id)->activeForVending()->first();
        $sources = \App\Support\InternalBeta::enabled() ? [EmployeeSource::MANUAL, EmployeeSource::DEMO] : [EmployeeSource::MANUAL];
        if (! $current || ! $person || $current->employee_id !== $person->id || ! in_array($person->source, $sources, true)) {
            return null;
        }
        foreach (app(LocalBetaTesterRegistry::class)->entries() as $entry) {
            if ($entry['user_id'] === $current->id && $entry['employee_id'] === $person->id
                && $entry['employee_number'] === $person->employee_number
                && $entry['employee_source'] === $person->source->value && $entry['enabled']
                && CarbonImmutable::parse($entry['updated_at'])->lte(CarbonImmutable::now('UTC'))
                && CarbonImmutable::parse($entry['expires_at'])->gt(CarbonImmutable::now('UTC'))) {
                return $entry;
            }
        }

        return null;
    }

    public function allows(User $user, Employee $employee): bool
    {
        return $this->entry($user, $employee) !== null;
    }
}
