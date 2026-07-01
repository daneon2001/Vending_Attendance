<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditCleanupRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'initiated_by_user_id',
        'trigger_source',
        'status',
        'mode',
        'settings_snapshot',
        'filters_snapshot',
        'summary',
        'deleted_records',
        'estimated_bytes_freed',
        'optimized',
        'optimize_statement_ran',
        'started_at',
        'finished_at',
        'duration_ms',
        'error_message',
    ];

    protected $casts = [
        'settings_snapshot' => 'array',
        'filters_snapshot' => 'array',
        'summary' => 'array',
        'deleted_records' => 'integer',
        'estimated_bytes_freed' => 'integer',
        'optimized' => 'boolean',
        'optimize_statement_ran' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'initiated_by_user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
