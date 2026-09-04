<?php

namespace App\Services\Vending;

use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Support\Facades\DB;

class EmployeeManifestVersionService
{
    private const EMPLOYEE_MANIFEST_ATTRIBUTES = [
        'fortia_employee_id', 'name', 'last_name', 'second_last_name', 'full_name', 'status',
    ];

    public function bumpMachine(int|VendingMachine $machine, string $reason): int
    {
        $machineId = $machine instanceof VendingMachine ? $machine->getKey() : $machine;

        return DB::transaction(function () use ($machineId, $reason): int {
            $locked = VendingMachine::query()->whereKey($machineId)->lockForUpdate()->firstOrFail();
            $previous = (int) $locked->employee_manifest_version;
            $locked->forceFill([
                'employee_manifest_version' => $previous + 1,
                'employee_manifest_state_hash' => null,
            ])->save();

            AuditLogger::log('employee_manifest.version_changed', $locked, 'Employee manifest version changed.', [
                'before' => ['employee_manifest_version' => $previous],
                'after' => ['employee_manifest_version' => $previous + 1],
                'reason' => $reason,
            ]);

            return $previous + 1;
        }, 3);
    }

    /**
     * @param  Closure(VendingMachine):string  $stateHashResolver
     * @return array{machine:VendingMachine,state_hash:string}
     */
    public function resolveCurrentState(VendingMachine $machine, Closure $stateHashResolver): array
    {
        return DB::transaction(function () use ($machine, $stateHashResolver): array {
            $locked = VendingMachine::query()->whereKey($machine->getKey())->lockForUpdate()->firstOrFail();
            $stateHash = $stateHashResolver($locked);
            $storedHash = $locked->employee_manifest_state_hash;

            if ($storedHash === null) {
                $locked->forceFill(['employee_manifest_state_hash' => $stateHash])->save();
            } elseif (! hash_equals($storedHash, $stateHash)) {
                $previous = (int) $locked->employee_manifest_version;
                $locked->forceFill([
                    'employee_manifest_version' => $previous + 1,
                    'employee_manifest_state_hash' => $stateHash,
                ])->save();

                AuditLogger::log('employee_manifest.version_changed', $locked, 'Effective employee manifest changed at a validity boundary.', [
                    'before' => ['employee_manifest_version' => $previous],
                    'after' => ['employee_manifest_version' => $previous + 1],
                    'reason' => 'effective_scope_changed',
                ]);
            }

            return ['machine' => $locked->fresh(), 'state_hash' => $stateHash];
        }, 3);
    }

    public function bumpForAssignment(EmployeeMachineAssignment $assignment, string $reason): void
    {
        $machineIds = collect([
            $assignment->vending_machine_id,
            $assignment->getRawOriginal('vending_machine_id'),
        ])->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();

        foreach ($machineIds as $machineId) {
            $this->bumpMachine($machineId, $reason);
        }
    }

    public function bumpForEmployee(Employee $employee): void
    {
        if (! $employee->wasChanged(self::EMPLOYEE_MANIFEST_ATTRIBUTES)) {
            return;
        }

        $machineIds = EmployeeMachineAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->active()
            ->effectiveAt(now())
            ->withRelevantPermission()
            ->distinct()
            ->orderBy('vending_machine_id')
            ->pluck('vending_machine_id');

        foreach ($machineIds as $machineId) {
            $this->bumpMachine((int) $machineId, 'employee.operational_identity_changed');
        }
    }
}
