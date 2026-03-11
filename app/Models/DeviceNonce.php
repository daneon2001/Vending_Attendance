<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceNonce extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'nonce',
        'seen_at',
        'expires_at',
    ];

    protected $casts = [
        'seen_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}

