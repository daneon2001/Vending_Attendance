<?php

namespace App\Models;

use App\Enums\Vending\AssignmentStatus;
use App\Enums\Vending\AssignmentType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EmployeeMachineAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'employee_id', 'vending_machine_id', 'assignment_type',
        'valid_from', 'valid_until', 'attendance_allowed', 'enrollment_allowed',
        'maintenance_allowed', 'status', 'source', 'created_by', 'revoked_at',
        'revoked_by', 'revocation_reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'assignment_type' => AssignmentType::class,
            'status' => AssignmentStatus::class,
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'revoked_at' => 'datetime',
            'attendance_allowed' => 'boolean',
            'enrollment_allowed' => 'boolean',
            'maintenance_allowed' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            $assignment->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function vendingMachine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', AssignmentStatus::ACTIVE->value)
            ->whereNull('revoked_at');
    }

    public function scopeEffectiveAt(Builder $query, DateTimeInterface|string|null $timestamp = null): Builder
    {
        $at = $timestamp instanceof DateTimeInterface
            ? Carbon::instance($timestamp)
            : Carbon::parse($timestamp ?? 'now');

        return $query
            ->where('valid_from', '<=', $at)
            ->where(fn (Builder $window) => $window
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>=', $at));
    }

    public function scopeAttendanceAllowed(Builder $query): Builder
    {
        return $query->where('attendance_allowed', true);
    }

    public function scopeEnrollmentAllowed(Builder $query): Builder
    {
        return $query->where('enrollment_allowed', true);
    }

    public function scopeMaintenanceAllowed(Builder $query): Builder
    {
        return $query->where('maintenance_allowed', true);
    }

    public function revoke(?int $actorId = null, ?string $reason = null, DateTimeInterface|string|null $at = null): void
    {
        $this->forceFill([
            'status' => AssignmentStatus::REVOKED,
            'revoked_at' => $at ? Carbon::parse($at) : now(),
            'revoked_by' => $actorId,
            'revocation_reason' => $reason,
        ])->save();
    }
}
