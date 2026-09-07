<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportEvidence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'declared_size' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    protected $table = 'support_evidence';

    protected $hidden = ['storage_key', 'thumbnail_key', 'disk'];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $evidence): void {
            if ($evidence->getOriginal('status') === 'CONFIRMED') {
                throw new \LogicException('Confirmed evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Evidence history is immutable.'));
    }
}
