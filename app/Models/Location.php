<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'fortia_location_id',
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

    public function employees()
    {
        $ownerKey = 'id';

        foreach (['fortia_location_id', 'external_id', 'legacy_code', 'code'] as $candidate) {
            if (Schema::hasColumn($this->getTable(), $candidate)) {
                $ownerKey = $candidate;
                break;
            }
        }

        return $this->hasMany(Employee::class, 'base_location_id', $ownerKey);
    }
}
