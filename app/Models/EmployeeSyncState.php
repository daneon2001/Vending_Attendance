<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSyncState extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'last_synced_at',
        'last_sync_status',
        'last_sync_message',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];
}
