<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'city',
        'state',
        'country',
        'timezone',
        'address',
        'latitude',
        'longitude',
        'status',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function clocks()
    {
        return $this->hasMany(Clock::class);
    }
}
