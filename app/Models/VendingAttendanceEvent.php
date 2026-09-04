<?php

namespace App\Models;

use App\Enums\Vending\AttendanceAuthorizationReason;
use App\Enums\Vending\AttendanceAuthorizationResult;
use App\Enums\Vending\AttendanceBiometricResult;
use App\Enums\Vending\AttendanceGeofenceResult;
use App\Enums\Vending\AttendanceLocationEvidenceStatus;
use App\Enums\Vending\AttendanceManifestEvidenceStatus;
use App\Enums\Vending\AttendanceReceiptStatus;
use App\Enums\Vending\VendingAttendanceEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VendingAttendanceEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'event_uuid', 'device_id', 'vending_machine_id', 'employee_id',
        'employee_machine_assignment_id', 'assignment_uuid_snapshot', 'event_type',
        'captured_at_device', 'received_at_server', 'device_timezone',
        'device_clock_drift_seconds', 'sync_delay_seconds', 'latitude', 'longitude',
        'accuracy_m', 'location_evidence_status', 'geofence_id', 'geofence_version',
        'edge_geofence_result', 'server_geofence_result', 'distance_m',
        'effective_distance_m', 'geofence_discrepancy', 'authorization_result',
        'authorization_reason', 'employee_manifest_version',
        'employee_manifest_evidence_status', 'configuration_version',
        'configuration_evidence_status', 'employee_number_snapshot',
        'assignment_type_snapshot', 'assignment_valid_from_snapshot',
        'assignment_valid_until_snapshot', 'attendance_allowed_snapshot',
        'machine_code_snapshot', 'geofence_radius_snapshot',
        'geofence_latitude_snapshot', 'geofence_longitude_snapshot',
        'geofence_tolerance_snapshot', 'biometric_result', 'biometric_reference',
        'sync_status', 'payload_hash', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => VendingAttendanceEventType::class,
            'captured_at_device' => 'immutable_datetime',
            'received_at_server' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'location_evidence_status' => AttendanceLocationEvidenceStatus::class,
            'edge_geofence_result' => AttendanceGeofenceResult::class,
            'server_geofence_result' => AttendanceGeofenceResult::class,
            'geofence_discrepancy' => 'boolean',
            'authorization_result' => AttendanceAuthorizationResult::class,
            'authorization_reason' => AttendanceAuthorizationReason::class,
            'employee_manifest_evidence_status' => AttendanceManifestEvidenceStatus::class,
            'configuration_evidence_status' => AttendanceManifestEvidenceStatus::class,
            'biometric_result' => AttendanceBiometricResult::class,
            'sync_status' => AttendanceReceiptStatus::class,
            'attendance_allowed_snapshot' => 'boolean',
            'assignment_valid_from_snapshot' => 'immutable_datetime',
            'assignment_valid_until_snapshot' => 'immutable_datetime',
            'employee_manifest_version' => 'integer',
            'configuration_version' => 'integer',
            'geofence_version' => 'integer',
            'device_clock_drift_seconds' => 'integer',
            'sync_delay_seconds' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_m' => 'decimal:2',
            'distance_m' => 'decimal:3',
            'effective_distance_m' => 'decimal:3',
            'geofence_radius_snapshot' => 'decimal:2',
            'geofence_latitude_snapshot' => 'decimal:7',
            'geofence_longitude_snapshot' => 'decimal:7',
            'geofence_tolerance_snapshot' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Vending attendance evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Vending attendance evidence cannot be deleted through the model.'));
    }

    public function getRouteKeyName(): string
    {
        return 'event_uuid';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function vendingMachine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeMachineAssignment::class, 'employee_machine_assignment_id');
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(MachineGeofence::class, 'geofence_id');
    }
}
