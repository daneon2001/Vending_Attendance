<?php

namespace App\Models;

use App\Enums\Vending\MobileReleaseTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileReleaseTarget extends Model
{
    protected $fillable = ['mobile_release_id', 'target_type', 'target_value'];

    protected function casts(): array
    {
        return ['target_type' => MobileReleaseTargetType::class];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(MobileRelease::class, 'mobile_release_id');
    }
}
