<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClockLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'clock_id',
        'event_type',
        'level',
        'source',
        'message',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function clock()
    {
        return $this->belongsTo(Clock::class);
    }
}
