<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportPolicyVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $policy): void {
            if ($policy->isDirty(['version', 'label', 'is_demo', 'valid_from', 'valid_until', 'payload', 'created_by'])) {
                throw new \LogicException('Support policies are versioned; publish a new version.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Support policy history is immutable.'));
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'active' => 'boolean',
            'payload' => 'array',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
        ];
    }
}
