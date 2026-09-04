<?php

namespace App\Models;

use App\Enums\Vending\CoordinateSource;
use App\Enums\Vending\GeofenceStatus;
use App\Enums\Vending\SybiVendingSyncStatus;
use App\Enums\Vending\VendingCatalogSource;
use App\Enums\Vending\VendingMachineStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class VendingMachine extends Model
{
    use HasFactory;

    protected $hidden = ['employee_manifest_state_hash'];

    private const VERSIONED_ATTRIBUTES = [
        'sybi_id', 'machine_code', 'operational_code', 'status',
        'latitude', 'longitude', 'coordinate_source', 'coordinates_verified',
        'coordinates_verified_at', 'timezone', 'default_geofence_radius_m',
        'installed_at', 'retired_at',
    ];

    protected $fillable = [
        'uuid', 'sybi_id', 'source', 'sybi_city_id', 'sybi_state_id',
        'sybi_full_address', 'sybi_last_seen_at', 'sybi_sync_status',
        'geofence_review_required', 'sybi_coordinates_changed_at',
        'machine_code', 'operational_code', 'name',
        'address_line', 'neighborhood', 'locality', 'municipality', 'state',
        'postal_code', 'country', 'latitude', 'longitude', 'coordinate_source',
        'coordinates_verified', 'coordinates_verified_at', 'timezone', 'status',
        'default_geofence_radius_m', 'installed_at', 'retired_at',
        'config_version', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => VendingMachineStatus::class,
            'coordinate_source' => CoordinateSource::class,
            'source' => VendingCatalogSource::class,
            'sybi_sync_status' => SybiVendingSyncStatus::class,
            'sybi_city_id' => 'integer',
            'sybi_state_id' => 'integer',
            'sybi_last_seen_at' => 'immutable_datetime',
            'sybi_coordinates_changed_at' => 'immutable_datetime',
            'geofence_review_required' => 'boolean',
            'coordinates_verified' => 'boolean',
            'coordinates_verified_at' => 'datetime',
            'installed_at' => 'datetime',
            'retired_at' => 'datetime',
            'config_version' => 'integer',
            'employee_manifest_version' => 'integer',
            'default_geofence_radius_m' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $machine): void {
            $machine->uuid ??= (string) Str::uuid();
            $machine->config_version ??= 1;
        });

        static::updating(function (self $machine): void {
            if (! $machine->isDirty('config_version') && $machine->isDirty(self::VERSIONED_ATTRIBUTES)) {
                $machine->config_version = ((int) $machine->getOriginal('config_version')) + 1;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeMachineAssignment::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_machine_assignments')
            ->withPivot([
                'uuid', 'assignment_type', 'valid_from', 'valid_until',
                'attendance_allowed', 'enrollment_allowed', 'maintenance_allowed',
                'status', 'source', 'revoked_at', 'revocation_reason',
            ])
            ->withTimestamps();
    }

    public function geofences(): HasMany
    {
        return $this->hasMany(MachineGeofence::class);
    }

    public function activeGeofence(): HasOne
    {
        return $this->hasOne(MachineGeofence::class)
            ->where('status', GeofenceStatus::ACTIVE->value)
            ->latestOfMany('version');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(VendingAttendanceEvent::class);
    }

    public function sybiSourceRecord(): HasOne
    {
        return $this->hasOne(SybiVendingSourceRecord::class, 'promoted_vending_machine_id');
    }

    public function provisioningTokens(): HasMany
    {
        return $this->hasMany(DeviceProvisioningToken::class);
    }

    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereIn('status', [
            VendingMachineStatus::ACTIVE->value,
            VendingMachineStatus::MAINTENANCE->value,
        ]);
    }

    public function hasVerifiedCoordinates(): bool
    {
        if (! $this->coordinates_verified || $this->latitude === null || $this->longitude === null) {
            return false;
        }

        $latitude = (float) $this->latitude;
        $longitude = (float) $this->longitude;

        return $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180
            && ! ($latitude === 0.0 && $longitude === 0.0);
    }
}
