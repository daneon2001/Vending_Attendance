<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AuditCleanupSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'critical_retention_days',
        'important_retention_days',
        'noise_retention_days',
        'batch_size',
        'heartbeat_log_interval_minutes',
        'optimize_min_deleted_mb',
        'updated_by_user_id',
    ];

    protected $casts = [
        'critical_retention_days' => 'integer',
        'important_retention_days' => 'integer',
        'noise_retention_days' => 'integer',
        'batch_size' => 'integer',
        'heartbeat_log_interval_minutes' => 'integer',
        'optimize_min_deleted_mb' => 'integer',
        'updated_by_user_id' => 'integer',
    ];

    public static function defaults(): array
    {
        return [
            'critical_retention_days' => (int) config('audit.cleanup.critical_retention_days', 3650),
            'important_retention_days' => (int) config('audit.cleanup.important_retention_days', 180),
            'noise_retention_days' => (int) config('audit.cleanup.noise_retention_days', 7),
            'batch_size' => (int) config('audit.cleanup.batch_size', 5000),
            'heartbeat_log_interval_minutes' => (int) config('audit.cleanup.heartbeat_log_interval_minutes', 30),
            'optimize_min_deleted_mb' => (int) config('audit.cleanup.optimize_min_deleted_mb', 512),
        ];
    }

    public static function singleton(): self
    {
        if (! Schema::hasTable((new static())->getTable())) {
            return new static(static::defaults());
        }

        return static::query()->firstOrCreate([], static::defaults());
    }
}
