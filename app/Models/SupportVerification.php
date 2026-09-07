<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportVerification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function vendingMachine()
    {
        return $this->belongsTo(VendingMachine::class);
    }
}
