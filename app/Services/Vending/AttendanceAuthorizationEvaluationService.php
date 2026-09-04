<?php

namespace App\Services\Vending;

use App\Enums\Vending\AssignmentStatus;
use App\Enums\Vending\AttendanceAuthorizationReason;
use App\Enums\Vending\AttendanceAuthorizationResult;
use App\Enums\Vending\AttendanceManifestEvidenceStatus;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;
use Carbon\CarbonImmutable;

class AttendanceAuthorizationEvaluationService
{
    /** @return array{assignment:?EmployeeMachineAssignment,result:AttendanceAuthorizationResult,reason:AttendanceAuthorizationReason} */
    public function evaluate(
        Employee $employee,
        VendingMachine $machine,
        string $assignmentUuid,
        CarbonImmutable $capturedAt,
        AttendanceManifestEvidenceStatus $employeeManifestEvidence,
        AttendanceManifestEvidenceStatus $configurationEvidence,
    ): array {
        $assignment = EmployeeMachineAssignment::query()->where('uuid', $assignmentUuid)->first();

        if (! $assignment) {
            return $this->result(null, AttendanceAuthorizationResult::UNVERIFIABLE, AttendanceAuthorizationReason::ASSIGNMENT_NOT_FOUND);
        }

        if ((int) $assignment->employee_id !== (int) $employee->getKey()) {
            return $this->result($assignment, AttendanceAuthorizationResult::DENIED, AttendanceAuthorizationReason::ASSIGNMENT_EMPLOYEE_MISMATCH);
        }

        if ((int) $assignment->vending_machine_id !== (int) $machine->getKey()) {
            return $this->result($assignment, AttendanceAuthorizationResult::DENIED, AttendanceAuthorizationReason::ASSIGNMENT_MACHINE_MISMATCH);
        }

        if ($assignment->valid_from->isAfter($capturedAt)) {
            return $this->result($assignment, AttendanceAuthorizationResult::DENIED, AttendanceAuthorizationReason::ASSIGNMENT_NOT_YET_EFFECTIVE);
        }

        if ($assignment->valid_until !== null && $assignment->valid_until->isBefore($capturedAt)) {
            return $this->result($assignment, AttendanceAuthorizationResult::DENIED, AttendanceAuthorizationReason::ASSIGNMENT_EXPIRED);
        }

        if ($assignment->revoked_at !== null && $assignment->revoked_at->lessThanOrEqualTo($capturedAt)) {
            return $this->result($assignment, AttendanceAuthorizationResult::DENIED, AttendanceAuthorizationReason::ASSIGNMENT_REVOKED);
        }

        if ($assignment->status === AssignmentStatus::INACTIVE) {
            return $this->historicalOrDenied(
                $assignment,
                $capturedAt,
                $employeeManifestEvidence,
                AttendanceAuthorizationReason::ASSIGNMENT_INACTIVE,
            );
        }

        if ($assignment->status === AssignmentStatus::REVOKED && $assignment->revoked_at === null) {
            return $this->result($assignment, AttendanceAuthorizationResult::UNVERIFIABLE, AttendanceAuthorizationReason::HISTORICAL_STATE_UNAVAILABLE);
        }

        if ($assignment->status === AssignmentStatus::EXPIRED) {
            return $this->historicalOrDenied(
                $assignment,
                $capturedAt,
                $employeeManifestEvidence,
                AttendanceAuthorizationReason::ASSIGNMENT_EXPIRED,
            );
        }

        if (! $assignment->attendance_allowed) {
            return $this->historicalOrDenied(
                $assignment,
                $capturedAt,
                $employeeManifestEvidence,
                AttendanceAuthorizationReason::ATTENDANCE_NOT_ALLOWED,
            );
        }

        if (! in_array((string) $employee->status, Employee::VENDING_ACTIVE_STATUSES, true)) {
            if ($employeeManifestEvidence !== AttendanceManifestEvidenceStatus::CURRENT
                && $employee->updated_at !== null
                && $employee->updated_at->isAfter($capturedAt)) {
                return $this->result($assignment, AttendanceAuthorizationResult::UNVERIFIABLE, AttendanceAuthorizationReason::HISTORICAL_STATE_UNAVAILABLE);
            }

            return $this->result($assignment, AttendanceAuthorizationResult::DENIED, AttendanceAuthorizationReason::EMPLOYEE_INACTIVE);
        }

        if ($machine->status !== VendingMachineStatus::ACTIVE) {
            if ($configurationEvidence !== AttendanceManifestEvidenceStatus::CURRENT
                && $machine->updated_at !== null
                && $machine->updated_at->isAfter($capturedAt)) {
                return $this->result($assignment, AttendanceAuthorizationResult::UNVERIFIABLE, AttendanceAuthorizationReason::HISTORICAL_STATE_UNAVAILABLE);
            }

            return $this->result($assignment, AttendanceAuthorizationResult::DENIED, AttendanceAuthorizationReason::MACHINE_NOT_OPERATIONAL);
        }

        return $this->result($assignment, AttendanceAuthorizationResult::AUTHORIZED, AttendanceAuthorizationReason::ASSIGNMENT_VALID);
    }

    private function historicalOrDenied(
        EmployeeMachineAssignment $assignment,
        CarbonImmutable $capturedAt,
        AttendanceManifestEvidenceStatus $manifestEvidence,
        AttendanceAuthorizationReason $deniedReason,
    ): array {
        if ($manifestEvidence !== AttendanceManifestEvidenceStatus::CURRENT
            && $assignment->updated_at !== null
            && $assignment->updated_at->isAfter($capturedAt)) {
            return $this->result($assignment, AttendanceAuthorizationResult::UNVERIFIABLE, AttendanceAuthorizationReason::HISTORICAL_STATE_UNAVAILABLE);
        }

        return $this->result($assignment, AttendanceAuthorizationResult::DENIED, $deniedReason);
    }

    private function result(
        ?EmployeeMachineAssignment $assignment,
        AttendanceAuthorizationResult $result,
        AttendanceAuthorizationReason $reason,
    ): array {
        return compact('assignment', 'result', 'reason');
    }
}
