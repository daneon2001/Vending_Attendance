<?php

namespace App\Services\Vending;

use App\Enums\Vending\AssignmentStatus;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;

class MachineAssignmentService
{
    public function create(VendingMachine $machine, array $attributes, ?int $actorId = null): EmployeeMachineAssignment
    {
        return $machine->assignments()->create(array_merge($attributes, [
            'status' => $attributes['status'] ?? AssignmentStatus::ACTIVE->value,
            'created_by' => $actorId,
        ]));
    }

    public function revoke(EmployeeMachineAssignment $assignment, ?int $actorId = null, ?string $reason = null): EmployeeMachineAssignment
    {
        if ($assignment->status !== AssignmentStatus::REVOKED) {
            $assignment->revoke($actorId, $reason);
        }

        return $assignment->fresh();
    }
}
