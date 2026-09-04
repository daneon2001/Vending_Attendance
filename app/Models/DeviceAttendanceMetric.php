<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAttendanceMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id', 'stored_total', 'duplicate_total', 'rejected_total',
        'geofence_mismatch_total', 'sync_delay_total_seconds',
        'sync_delay_samples', 'sync_delay_max_seconds', 'last_attendance_received_at',
    ];

    protected function casts(): array
    {
        return [
            'stored_total' => 'integer',
            'duplicate_total' => 'integer',
            'rejected_total' => 'integer',
            'geofence_mismatch_total' => 'integer',
            'sync_delay_total_seconds' => 'integer',
            'sync_delay_samples' => 'integer',
            'sync_delay_max_seconds' => 'integer',
            'last_attendance_received_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
