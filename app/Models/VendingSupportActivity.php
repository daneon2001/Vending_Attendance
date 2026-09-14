<?php

namespace App\Models;

use App\Enums\Support\SupportActivityStatus;
use App\Enums\Support\SupportActivityType;
use App\Enums\Vending\GeofenceValidationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VendingSupportActivity extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['started_latitude', 'started_longitude', 'started_accuracy_m'];

    protected function casts(): array
    {
        return [
            'activity_type' => SupportActivityType::class, 'status' => SupportActivityStatus::class,
            'geofence_result' => GeofenceValidationResult::class,
            'requires_physical_presence' => 'boolean',
            'scheduled_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
            'started_captured_at' => 'immutable_datetime', 'geofence_evaluated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Use the activity transition service.'));
        static::deleting(static fn () => throw new LogicException('Activity history cannot be deleted.'));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function vendingMachine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assigneeActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function machineGeofence(): BelongsTo
    {
        return $this->belongsTo(MachineGeofence::class);
    }
}
