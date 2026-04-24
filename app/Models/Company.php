<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'fortia_company_id',
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

    public function employees()
    {
        $ownerKey = 'id';

        foreach (['fortia_company_id', 'external_id', 'legacy_code', 'code'] as $candidate) {
            if (Schema::hasColumn($this->getTable(), $candidate)) {
                $ownerKey = $candidate;
                break;
            }
        }

        return $this->hasMany(Employee::class, 'company_id', $ownerKey);
    }
}
