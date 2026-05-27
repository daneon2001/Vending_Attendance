<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Clock extends Model
{
    use HasFactory;

    public const HEARTBEAT_ONLINE_THRESHOLD_MINUTES = 5;

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
        'last_seen_ip',
        'monitoring_status',
        'program_status',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
        'status' => 'integer',
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

    public function logs()
    {
        return $this->hasMany(ClockLog::class);
    }

    public static function heartbeatOnlineThreshold(): Carbon
    {
        return now()->subMinutes(self::HEARTBEAT_ONLINE_THRESHOLD_MINUTES);
    }

    public function hasRecentHeartbeat(): bool
    {
        if (! $this->last_heartbeat_at instanceof Carbon) {
            return false;
        }

        return $this->last_heartbeat_at->greaterThanOrEqualTo(self::heartbeatOnlineThreshold());
    }

    public function resolvedMonitoringStatus(): string
    {
        if ($this->hasRecentHeartbeat()) {
            return 'online';
        }

        if ($this->monitoring_status === 'warning' && $this->last_heartbeat_at instanceof Carbon) {
            return 'warning';
        }

        return 'offline';
    }

    public function scopeMonitoringOnline(Builder $query): Builder
    {
        return $query
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', self::heartbeatOnlineThreshold());
    }

    public function scopeMonitoringWarning(Builder $query): Builder
    {
        return $query
            ->where('monitoring_status', 'warning')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', self::heartbeatOnlineThreshold());
    }

    public function scopeMonitoringOffline(Builder $query): Builder
    {
        return $query->where(function (Builder $offlineQuery): void {
            $offlineQuery
                ->whereNull('last_heartbeat_at')
                ->orWhere(function (Builder $staleQuery): void {
                    $staleQuery
                        ->where('last_heartbeat_at', '<', self::heartbeatOnlineThreshold())
                        ->where(function (Builder $warningQuery): void {
                            $warningQuery
                                ->whereNull('monitoring_status')
                                ->orWhere('monitoring_status', '!=', 'warning');
                        });
                });
        });
    }

    public function getIsOnlineAttribute(): bool
    {
        return $this->hasRecentHeartbeat();
    }
}
