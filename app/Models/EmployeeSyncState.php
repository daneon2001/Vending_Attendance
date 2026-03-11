<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSyncState extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'source',
        'last_cursor',
        'last_synced_at',
        'last_success_at',
        'last_sync_status',
        'last_error',
        'last_counts',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_counts' => 'array',
    ];
}
