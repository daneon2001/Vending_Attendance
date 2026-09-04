<?php

namespace App\Observers;

use App\Enums\Vending\AssignmentStatus;
use App\Models\EmployeeMachineAssignment;
use App\Services\Audit\AuditLogger;

class EmployeeMachineAssignmentObserver
{
    private const AUDITED = ['uuid', 'employee_id', 'vending_machine_id', 'assignment_type', 'valid_from', 'valid_until', 'attendance_allowed', 'enrollment_allowed', 'maintenance_allowed', 'status', 'source', 'created_by', 'revoked_at', 'revoked_by', 'revocation_reason'];

    public function created(EmployeeMachineAssignment $assignment): void
    {
        AuditLogger::log('assignment.created', $assignment, 'Employee-machine assignment created.', [
            'after' => array_intersect_key($assignment->getAttributes(), array_flip(self::AUDITED)),
        ]);
    }

    public function updated(EmployeeMachineAssignment $assignment): void
    {
        $changes = array_intersect(array_keys($assignment->getChanges()), self::AUDITED);
        if ($changes === []) {
            return;
        }

        $event = ($assignment->getAttributes()['status'] ?? null) === AssignmentStatus::REVOKED->value
            ? 'assignment.revoked'
            : 'assignment.updated';

        AuditLogger::log($event, $assignment, 'Employee-machine assignment updated.', [
            'before' => collect($changes)->mapWithKeys(fn ($key) => [$key => $assignment->getRawOriginal($key)])->all(),
            'after' => collect($changes)->mapWithKeys(fn ($key) => [$key => $assignment->getAttributes()[$key] ?? null])->all(),
        ]);
    }
}
