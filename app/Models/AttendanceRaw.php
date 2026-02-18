<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRaw extends Model
{
    use HasFactory;

    protected $table = 'attendances_raw';
    protected $primaryKey = 'remote_event_id';

    protected $fillable = [
        'device_id',
        'device_serial',
        'local_event_id',
        'collaborator_id',
        'clock_id',
        'unit_id',
        'company_id',
        'event_time_utc',
        'event_time_local',
        'tz',
        'type',
        'source',
        'meta',
    ];

    protected $casts = [
        'event_time_utc' => 'datetime',
        'event_time_local' => 'datetime',
        'meta' => 'array',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}

