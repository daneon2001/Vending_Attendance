<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Device extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $device): void {
            if (trim((string) $device->shared_secret) !== '') {
                return;
            }

            $configuredSecret = trim((string) config('onprem.default_shared_secret', ''));
            $device->shared_secret = $configuredSecret !== ''
                ? $configuredSecret
                : Str::random(64);
        });
    }

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
