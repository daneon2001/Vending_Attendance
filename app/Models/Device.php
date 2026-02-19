<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_serial',
        'clock_id',
        'unit_id',
        'company_id',
        'shared_secret',
        'is_active',
        'last_seen_at',
        'last_heartbeat_at',
        'last_status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
    ];

    public function clock()
    {
        return $this->belongsTo(Clock::class, 'clock_id');
    }

    public function unit()
    {
        return $this->belongsTo(Location::class, 'unit_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function nonces()
    {
        return $this->hasMany(DeviceNonce::class);
    }

    public function attendancesRaw()
    {
        return $this->hasMany(AttendanceRaw::class);
    }
}
