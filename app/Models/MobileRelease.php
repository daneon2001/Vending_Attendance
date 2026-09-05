<?php

namespace App\Models;

use App\Enums\Vending\MobilePlatform;
use App\Enums\Vending\MobileReleaseChannel;
use App\Enums\Vending\MobileReleaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MobileRelease extends Model
{
    protected $fillable = [
        'uuid', 'platform', 'channel', 'version', 'build_number', 'status',
        'minimum_os', 'artifact_url', 'artifact_sha256', 'mandatory',
        'rollout_percentage', 'released_at', 'notes', 'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $release) => $release->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'platform' => MobilePlatform::class,
            'channel' => MobileReleaseChannel::class,
            'status' => MobileReleaseStatus::class,
            'build_number' => 'integer',
            'mandatory' => 'boolean',
            'rollout_percentage' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(MobileReleaseTarget::class);
    }
}
