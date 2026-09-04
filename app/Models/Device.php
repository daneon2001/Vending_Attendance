<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Device extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $device): void {
            if (Schema::hasColumn('devices', 'uuid')) {
                $device->uuid ??= (string) Str::uuid();
            }

            if (trim((string) $device->shared_secret) !== '') {
                return;
            }

            if ($device->vending_machine_id !== null) {
                $device->shared_secret = '';

                return;
            }

            $configuredSecret = trim((string) config('onprem.default_shared_secret', ''));
            $device->shared_secret = $configuredSecret !== ''
                ? $configuredSecret
                : Str::random(64);
        });

        static::saving(function (self $device): void {
            if (! Schema::hasColumn('devices', 'status')) {
                return;
            }

            if (! $device->status) {
                $device->status = $device->is_active === false
                    ? DeviceStatus::SUSPENDED
                    : DeviceStatus::ACTIVE;
            } elseif ($device->isDirty('is_active') && ! $device->isDirty('status')) {
                $device->status = $device->is_active
                    ? DeviceStatus::ACTIVE
                    : DeviceStatus::SUSPENDED;
            }

            $status = $device->status instanceof DeviceStatus
                ? $device->status
                : DeviceStatus::from((string) $device->status);

            $device->is_active = $status === DeviceStatus::ACTIVE;
            if (Schema::hasColumn('devices', 'active_vending_machine_id')) {
                $device->active_vending_machine_id = $status === DeviceStatus::ACTIVE
                    && $device->vending_machine_id !== null
                        ? $device->vending_machine_id
                        : null;
            }

            if (Schema::hasColumn('devices', 'activated_at') && $status === DeviceStatus::ACTIVE && $device->activated_at === null) {
                $device->activated_at = now();
            }
            if (Schema::hasColumn('devices', 'retired_at') && $status === DeviceStatus::RETIRED && $device->retired_at === null) {
                $device->retired_at = now();
            }
        });
    }

    protected $fillable = [
        'uuid',
        'device_serial',
        'device_name',
        'platform',
        'platform_version',
        'app_version',
        'hardware_model',
        'status',
        'provisioned_at',
        'activated_at',
        'retired_at',
        'config_version_applied',
        'employee_manifest_version_applied',
        'biometric_manifest_version_applied',
        'clock_id',
        'unit_id',
        'vending_machine_id',
        'company_id',
        'shared_secret',
        'is_active',
        'last_seen_at',
        'last_heartbeat_at',
        'last_status',
        'battery_level',
        'storage_free_mb',
        'pending_events_count',
        'device_time',
        'clock_drift_seconds',
        'metadata',
    ];

    protected $hidden = ['shared_secret', 'credential_secret'];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'credential_secret' => 'encrypted',
            'is_active' => 'boolean',
            'provisioned_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'retired_at' => 'datetime',
            'credential_issued_at' => 'datetime',
            'credential_revoked_at' => 'datetime',
            'device_time' => 'datetime',
            'config_version_applied' => 'integer',
            'employee_manifest_version_applied' => 'integer',
            'biometric_manifest_version_applied' => 'integer',
            'credential_version' => 'integer',
            'battery_level' => 'float',
            'storage_free_mb' => 'integer',
            'pending_events_count' => 'integer',
            'clock_drift_seconds' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function clock()
    {
        return $this->belongsTo(Clock::class, 'clock_id');
    }

    public function unit()
    {
        return $this->belongsTo(Location::class, 'unit_id');
    }

    public function vendingMachine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function nonces()
    {
        return $this->hasMany(DeviceNonce::class);
    }

    public function provisioningTokens(): HasMany
    {
        return $this->hasMany(DeviceProvisioningToken::class, 'used_by_device_id');
    }

    public function isOperationalVendingDevice(): bool
    {
        return $this->vending_machine_id !== null
            && $this->status === DeviceStatus::ACTIVE
            && $this->is_active
            && $this->credential_revoked_at === null
            && filled($this->credential_secret);
    }

    public function attendancesRaw()
    {
        return $this->hasMany(AttendanceRaw::class);
    }
}
