<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $table = 'locations';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'description',
        'city',
        'state',
        'country',
        'address',
        'timezone',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function clocks()
    {
        return $this->hasMany(Clock::class, 'location_id');
    }
}
