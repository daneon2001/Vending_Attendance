<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonInterface;

class Clock extends Model
{
    use HasFactory;

    public const ONLINE_HEARTBEAT_WINDOW_MINUTES = 5;

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

    protected $appends = ['is_online', 'connection_status', 'onprem_program_status'];

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

    public function scopeHeartbeatOnline(Builder $query, ?CarbonInterface $reference = null): Builder
    {
        return $query
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', self::heartbeatOnlineThreshold($reference));
    }

    public function scopeHeartbeatOffline(Builder $query, ?CarbonInterface $reference = null): Builder
    {
        $threshold = self::heartbeatOnlineThreshold($reference);

        return $query->where(function (Builder $offlineQuery) use ($threshold): void {
            $offlineQuery
                ->whereNull('last_heartbeat_at')
                ->orWhere('last_heartbeat_at', '<', $threshold);
        });
    }

    public function scopeWhereConnectionStatus(Builder $query, string $status, ?CarbonInterface $reference = null): Builder
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'online' => $query
                ->heartbeatOnline($reference)
                ->where(function (Builder $onlineQuery): void {
                    $onlineQuery
                        ->whereNull('monitoring_status')
                        ->orWhere('monitoring_status', '!=', 'warning');
                }),
            'warning' => $query
                ->heartbeatOnline($reference)
                ->where('monitoring_status', 'warning'),
            'offline' => $query->heartbeatOffline($reference),
            default => $query,
        };
    }

    public function scopeWhereOnPremProgramStatus(Builder $query, string $status, ?CarbonInterface $reference = null): Builder
    {
        $status = strtolower(trim($status));
        $offlineStatuses = ['offline', 'off', 'stopped', 'error', 'failed'];

        return match ($status) {
            'online' => $query
                ->heartbeatOnline($reference)
                ->where(function (Builder $onlineQuery) use ($offlineStatuses): void {
                    $onlineQuery
                        ->whereNull('program_status')
                        ->orWhereNotIn('program_status', array_merge($offlineStatuses, ['standby']));
                }),
            'standby' => $query
                ->heartbeatOnline($reference)
                ->where('program_status', 'standby'),
            'offline' => $query->where(function (Builder $offlineQuery) use ($reference, $offlineStatuses): void {
                $offlineQuery
                    ->heartbeatOffline($reference)
                    ->orWhere(function (Builder $explicitOfflineQuery) use ($reference, $offlineStatuses): void {
                        $explicitOfflineQuery
                            ->heartbeatOnline($reference)
                            ->whereIn('program_status', $offlineStatuses);
                    });
            }),
            default => $query,
        };
    }

    public function getIsOnlineAttribute(): bool
    {
        if (! $this->last_heartbeat_at instanceof Carbon) {
            return false;
        }

        return $this->last_heartbeat_at->greaterThanOrEqualTo(self::heartbeatOnlineThreshold());
    }

    public function getConnectionStatusAttribute(): string
    {
        if (! $this->is_online) {
            return 'offline';
        }

        return strtolower(trim((string) $this->monitoring_status)) === 'warning'
            ? 'warning'
            : 'online';
    }

    public function getOnpremProgramStatusAttribute(): string
    {
        if (! $this->is_online) {
            return 'offline';
        }

        $status = strtolower(trim((string) $this->program_status));

        if ($status === 'standby') {
            return 'standby';
        }

        if (in_array($status, ['offline', 'off', 'stopped', 'error', 'failed'], true)) {
            return 'offline';
        }

        return 'online';
    }

    public static function heartbeatOnlineThreshold(?CarbonInterface $reference = null): Carbon
    {
        $base = $reference instanceof CarbonInterface
            ? Carbon::instance(\DateTime::createFromInterface($reference))
            : now();

        return $base->copy()->subMinutes(self::ONLINE_HEARTBEAT_WINDOW_MINUTES);
    }
}
