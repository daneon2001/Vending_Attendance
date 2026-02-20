<?php

namespace App\Observers;

use App\Models\AttendanceRecord;
use App\Services\Attendance\AttendanceIntegrityService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;

class AttendanceRecordObserver
{
    public function created(AttendanceRecord $attendance): void
    {
        app(AttendanceIntegrityService::class)->sealRecord($attendance);

        $fresh = AttendanceRecord::query()->find($attendance->id) ?? $attendance;

        AuditLogger::log(
            event: 'attendance.record.created',
            auditable: $attendance,
            description: 'Attendance record created',
            metadata: [
                'action' => 'create',
                'entity' => 'attendance_logs',
                'entity_id' => (string) $attendance->id,
                'new_values' => Arr::only($fresh->toArray(), [
                    'id',
                    'log_id',
                    'employee_id',
                    'fortia_employee_id',
                    'company_id',
                    'location_id',
                    'device_id',
                    'device_serial',
                    'log_date',
                    'log_type',
                    'source',
                    'attendance_status',
                    'local_id',
                    'ingested_at_utc',
                    'ingest_ip',
                    'auth_key_id',
                    'request_id',
                    'integrity_hash',
                    'integrity_previous_hash',
                    'integrity_hash_version',
                ]),
                'device_id' => $attendance->device_id,
                'reason' => 'ingest',
            ],
        );
    }

    public function updating(AttendanceRecord $attendance): void
    {
        $attendance->setRelation('_audit_before', collect($attendance->getOriginal()));
    }

    public function updated(AttendanceRecord $attendance): void
    {
        $before = $attendance->relationLoaded('_audit_before')
            ? (array) $attendance->getRelation('_audit_before')->all()
            : [];
        $attendance->unsetRelation('_audit_before');

        $changedKeys = array_keys($attendance->getChanges());
        if ($changedKeys === []) {
            return;
        }

        $ignored = [
            'updated_at',
            'integrity_verified_at',
        ];

        $trackedChanges = array_values(array_filter(
            $changedKeys,
            fn (string $key) => ! in_array($key, $ignored, true)
        ));

        if ($trackedChanges === []) {
            return;
        }

        $current = $attendance->fresh();
        if (! $current) {
            return;
        }

        AuditLogger::log(
            event: 'attendance.record.updated',
            auditable: $attendance,
            description: 'Attendance record updated',
            metadata: [
                'action' => 'update',
                'entity' => 'attendance_logs',
                'entity_id' => (string) $attendance->id,
                'old_values' => Arr::only($before, $trackedChanges),
                'new_values' => Arr::only($current->toArray(), $trackedChanges),
                'device_id' => $attendance->device_id,
                'reason' => $attendance->adjustment_reason ?: 'record_update',
            ],
        );
    }

    public function deleted(AttendanceRecord $attendance): void
    {
        AuditLogger::log(
            event: 'attendance.record.deleted',
            auditable: $attendance,
            description: 'Attendance record deleted',
            metadata: [
                'action' => 'delete',
                'entity' => 'attendance_logs',
                'entity_id' => (string) $attendance->id,
                'old_values' => Arr::only($attendance->toArray(), [
                    'id',
                    'log_id',
                    'employee_id',
                    'fortia_employee_id',
                    'company_id',
                    'location_id',
                    'device_id',
                    'device_serial',
                    'log_date',
                    'log_type',
                    'source',
                    'attendance_status',
                    'local_id',
                    'ingested_at_utc',
                    'ingest_ip',
                    'auth_key_id',
                    'request_id',
                    'integrity_hash',
                    'integrity_previous_hash',
                    'integrity_hash_version',
                ]),
                'device_id' => $attendance->device_id,
                'reason' => 'record_delete',
            ],
        );
    }
}
