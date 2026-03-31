<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function locations()
    {
        return $this->hasMany(Location::class);
    }

    public function clocks()
    {
        return $this->hasMany(Clock::class);
    }
}
