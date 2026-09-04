<?php

namespace App\Models;

use App\Enums\Vending\GeofenceShape;
use App\Enums\Vending\GeofenceStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MachineGeofence extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'vending_machine_id', 'version', 'shape', 'center_latitude',
        'center_longitude', 'radius_m', 'minimum_acceptable_accuracy_m',
        'tolerance_m', 'valid_from', 'valid_until', 'status', 'source',
        'created_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'shape' => GeofenceShape::class,
            'status' => GeofenceStatus::class,
            'version' => 'integer',
            'center_latitude' => 'decimal:7',
            'center_longitude' => 'decimal:7',
            'radius_m' => 'integer',
            'minimum_acceptable_accuracy_m' => 'float',
            'tolerance_m' => 'float',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $geofence): void {
            $geofence->uuid ??= (string) Str::uuid();
        });

        static::saving(function (self $geofence): void {
            $status = $geofence->status instanceof GeofenceStatus
                ? $geofence->status
                : GeofenceStatus::tryFrom((string) $geofence->status);

            $geofence->active_machine_id = $status === GeofenceStatus::ACTIVE
                ? $geofence->vending_machine_id
                : null;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function vendingMachine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GeofenceStatus::ACTIVE->value);
    }

    public function scopeEffectiveAt(Builder $query, DateTimeInterface|string|null $timestamp = null): Builder
    {
        $at = $timestamp instanceof DateTimeInterface
            ? Carbon::instance($timestamp)
            : Carbon::parse($timestamp ?? 'now');

        return $query
            ->active()
            ->where(fn (Builder $window) => $window
                ->whereNull('valid_from')
                ->orWhere('valid_from', '<=', $at))
            ->where(fn (Builder $window) => $window
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>=', $at));
    }
}
