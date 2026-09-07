<?php

namespace App\Models;

use App\Enums\Employees\EmployeeImportRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmployeeImportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'initiated_by', 'file_name', 'file_hash', 'file_extension', 'status',
        'detected_mapping', 'total_rows', 'valid_new', 'valid_update', 'unchanged',
        'invalid', 'duplicates', 'conflicts', 'started_at', 'expires_at',
        'finished_at', 'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmployeeImportRunStatus::class,
            'detected_mapping' => 'array',
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'total_rows' => 'integer',
            'valid_new' => 'integer',
            'valid_update' => 'integer',
            'unchanged' => 'integer',
            'invalid' => 'integer',
            'duplicates' => 'integer',
            'conflicts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EmployeeImportRow::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
