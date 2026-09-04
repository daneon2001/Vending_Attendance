<?php

namespace App\Models;

use App\Enums\Vending\SybiVendingErrorCode;
use App\Enums\Vending\SybiVendingRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SybiVendingSyncRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'started_at', 'finished_at', 'status', 'received', 'created',
        'updated', 'unchanged', 'rejected', 'conflicts', 'missing', 'duration_ms',
        'http_status', 'error_code', 'error_message', 'warnings', 'initiated_by',
        'source_candidates', 'source_created', 'source_updated', 'source_unchanged',
        'source_invalid', 'operational_ready', 'operational_created',
        'operational_updated', 'operational_unchanged', 'operational_incomplete',
        'operational_conflicts', 'operational_invalid', 'operational_missing',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'status' => SybiVendingRunStatus::class,
            'error_code' => SybiVendingErrorCode::class,
            'warnings' => 'array',
            'received' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'unchanged' => 'integer',
            'rejected' => 'integer',
            'conflicts' => 'integer',
            'missing' => 'integer',
            'duration_ms' => 'integer',
            'http_status' => 'integer',
            'source_candidates' => 'integer',
            'source_created' => 'integer',
            'source_updated' => 'integer',
            'source_unchanged' => 'integer',
            'source_invalid' => 'integer',
            'operational_ready' => 'integer',
            'operational_created' => 'integer',
            'operational_updated' => 'integer',
            'operational_unchanged' => 'integer',
            'operational_incomplete' => 'integer',
            'operational_conflicts' => 'integer',
            'operational_invalid' => 'integer',
            'operational_missing' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
