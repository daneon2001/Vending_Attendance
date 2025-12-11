<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Clock extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'clock_name',
        'serial_number',
        'firmware_version',
        'ip_address',
        'type_inout',
        'status',
        'location_id',
        'last_heartbeat_at',
        'last_status_message',
        'monitoring_status',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
    ];

    protected $appends = ['is_online'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function getIsOnlineAttribute(): bool
    {
        if (! $this->last_heartbeat_at instanceof Carbon) {
            return false;
        }

        return $this->last_heartbeat_at->greaterThan(now()->subMinutes(5));
    }
}
