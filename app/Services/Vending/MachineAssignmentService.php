<?php

namespace App\Services\Vending;

use App\Enums\Vending\AssignmentStatus;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;
use Illuminate\Support\Facades\DB;

class MachineAssignmentService
{
    public function create(VendingMachine $machine, array $attributes, ?int $actorId = null): EmployeeMachineAssignment
    {
        return DB::transaction(function () use ($machine, $attributes, $actorId): EmployeeMachineAssignment {
            $locked = VendingMachine::query()->whereKey($machine->getKey())->lockForUpdate()->firstOrFail();

            return $locked->assignments()->create(array_merge($attributes, [
                'status' => $attributes['status'] ?? AssignmentStatus::ACTIVE->value,
                'created_by' => $actorId,
            ]));
        }, 3);
    }

    public function revoke(EmployeeMachineAssignment $assignment, ?int $actorId = null, ?string $reason = null): EmployeeMachineAssignment
    {
        return DB::transaction(function () use ($assignment, $actorId, $reason): EmployeeMachineAssignment {
            VendingMachine::query()->whereKey($assignment->vending_machine_id)->lockForUpdate()->firstOrFail();
            $locked = EmployeeMachineAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== AssignmentStatus::REVOKED) {
                $locked->revoke($actorId, $reason);
            }

            return $locked->fresh();
        }, 3);
    }
}
