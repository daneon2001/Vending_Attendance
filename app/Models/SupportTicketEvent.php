<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'public' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public const UPDATED_AT = null;

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Support history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Support history is append-only.'));
    }
}
