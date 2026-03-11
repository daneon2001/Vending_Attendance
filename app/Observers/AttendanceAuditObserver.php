<?php

namespace App\Observers;

use App\Models\AttendanceAudit;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;

class AttendanceAuditObserver
{
    public function created(AttendanceAudit $audit): void
    {
        AuditLogger::log(
            event: 'attendance.incidence.created',
            auditable: $audit,
            description: 'Attendance incidence/audit created',
            metadata: [
                'action' => 'create',
                'entity' => 'attendance_changes',
                'entity_id' => (string) $audit->id,
                'new_values' => Arr::only($audit->toArray(), [
                    'id',
                    'attendance_log_id',
                    'action',
                    'reason',
                    'changed_by_user_id',
                    'changed_by_name',
                    'changed_by_email',
                    'before_data',
                    'after_data',
                    'ip_address',
                    'user_agent',
                ]),
                'reason' => $audit->reason ?: 'incidence_created',
            ],
        );
    }

    public function updating(AttendanceAudit $audit): void
    {
        $audit->setRelation('_audit_before', collect($audit->getOriginal()));
    }

    public function updated(AttendanceAudit $audit): void
    {
        $before = $audit->relationLoaded('_audit_before')
            ? (array) $audit->getRelation('_audit_before')->all()
            : [];
        $audit->unsetRelation('_audit_before');

        $changedKeys = array_keys($audit->getChanges());
        $changedKeys = array_values(array_filter($changedKeys, fn (string $key) => $key !== 'updated_at'));
        if ($changedKeys === []) {
            return;
        }

        $fresh = $audit->fresh();
        if (! $fresh) {
            return;
        }

        AuditLogger::log(
            event: 'attendance.incidence.updated',
            auditable: $audit,
            description: 'Attendance incidence/audit updated',
            metadata: [
                'action' => 'update',
                'entity' => 'attendance_changes',
                'entity_id' => (string) $audit->id,
                'old_values' => Arr::only($before, $changedKeys),
                'new_values' => Arr::only($fresh->toArray(), $changedKeys),
                'reason' => $audit->reason ?: 'incidence_updated',
            ],
        );
    }

    public function deleted(AttendanceAudit $audit): void
    {
        AuditLogger::log(
            event: 'attendance.incidence.deleted',
            auditable: $audit,
            description: 'Attendance incidence/audit deleted',
            metadata: [
                'action' => 'delete',
                'entity' => 'attendance_changes',
                'entity_id' => (string) $audit->id,
                'old_values' => Arr::only($audit->toArray(), [
                    'id',
                    'attendance_log_id',
                    'action',
                    'reason',
                    'changed_by_user_id',
                    'changed_by_name',
                    'changed_by_email',
                    'before_data',
                    'after_data',
                    'ip_address',
                    'user_agent',
                ]),
                'reason' => $audit->reason ?: 'incidence_deleted',
            ],
        );
    }
}
