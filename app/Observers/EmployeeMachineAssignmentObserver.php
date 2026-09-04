<?php

namespace App\Observers;

use App\Enums\Vending\AssignmentStatus;
use App\Models\EmployeeMachineAssignment;
use App\Services\Audit\AuditLogger;
use App\Services\Vending\EmployeeManifestVersionService;

class EmployeeMachineAssignmentObserver
{
    private const AUDITED = ['uuid', 'employee_id', 'vending_machine_id', 'assignment_type', 'valid_from', 'valid_until', 'attendance_allowed', 'enrollment_allowed', 'maintenance_allowed', 'status', 'source', 'created_by', 'revoked_at', 'revoked_by', 'revocation_reason'];

    private const MANIFEST_RELEVANT = ['employee_id', 'vending_machine_id', 'assignment_type', 'valid_from', 'valid_until', 'attendance_allowed', 'enrollment_allowed', 'maintenance_allowed', 'status', 'revoked_at'];

    public function __construct(private readonly EmployeeManifestVersionService $manifestVersions) {}

    public function created(EmployeeMachineAssignment $assignment): void
    {
        AuditLogger::log('assignment.created', $assignment, 'Employee-machine assignment created.', [
            'after' => array_intersect_key($assignment->getAttributes(), array_flip(self::AUDITED)),
        ]);

        $this->manifestVersions->bumpForAssignment($assignment, 'assignment.created');
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

        if (array_intersect($changes, self::MANIFEST_RELEVANT) !== []) {
            $this->manifestVersions->bumpForAssignment($assignment, $event);
        }
    }
}
