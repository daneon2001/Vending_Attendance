<?php

namespace App\Models;

use App\Enums\Vending\MobilePlatform;
use App\Enums\Vending\MobileReleaseChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileReleasePolicy extends Model
{
    protected $fillable = [
        'platform', 'channel', 'current_release_id', 'recommended_release_id',
        'minimum_release_id', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'platform' => MobilePlatform::class,
            'channel' => MobileReleaseChannel::class,
        ];
    }

    public function currentRelease(): BelongsTo
    {
        return $this->belongsTo(MobileRelease::class, 'current_release_id');
    }

    public function recommendedRelease(): BelongsTo
    {
        return $this->belongsTo(MobileRelease::class, 'recommended_release_id');
    }

    public function minimumRelease(): BelongsTo
    {
        return $this->belongsTo(MobileRelease::class, 'minimum_release_id');
    }
}
