<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasFactory;

    protected $table = 'areas';

    protected $fillable = [
        'cla_area',
        'nom_area',
    ];

    public function employeeDetails()
    {
        return $this->hasMany(EmployeeDetail::class);
    }
}
