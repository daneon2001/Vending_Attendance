<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportActivityNote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Activity notes are immutable.'));
        static::deleting(fn () => throw new \LogicException('Activity notes are immutable.'));
    }
}
