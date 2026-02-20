<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actor_user_id',
        'actor_type',
        'actor_identifier',
        'user_name',
        'user_email',
        'event',
        'action',
        'entity',
        'entity_id',
        'auditable_type',
        'auditable_id',
        'description',
        'reason',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'request_id',
        'correlation_id',
        'device_id',
        'occurred_at_utc',
        'occurred_at_local',
        'timezone',
    ];

    protected $casts = [
        'metadata' => 'array',
        'old_values' => 'array',
        'new_values' => 'array',
        'occurred_at_utc' => 'datetime',
        'occurred_at_local' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            if (config('audit.prevent_updates', true) && ! self::canModifyAuditLog()) {
                throw new RuntimeException('Audit logs are append-only and cannot be updated.');
            }
        });

        static::deleting(function (): void {
            if (config('audit.prevent_deletes', true) && ! self::canModifyAuditLog()) {
                throw new RuntimeException('Audit logs are append-only and cannot be deleted.');
            }
        });
    }

    private static function canModifyAuditLog(): bool
    {
        if (! config('audit.allow_extreme_modification', false)) {
            return false;
        }

        $user = auth()->user();
        if (! $user || ! method_exists($user, 'hasPermission')) {
            return false;
        }

        return $user->hasPermission('settings', 'manage');
    }
}
