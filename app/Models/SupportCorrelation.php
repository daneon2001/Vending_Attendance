<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCorrelation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'last_active_at' => 'immutable_datetime',
            'recovered_at' => 'immutable_datetime',
            'cooldown_until' => 'immutable_datetime',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
