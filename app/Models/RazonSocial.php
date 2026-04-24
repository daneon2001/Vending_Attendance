<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RazonSocial extends Model
{
    use HasFactory;

    protected $table = 'razones_sociales';

    protected $fillable = [
        'cla_razon_social',
        'nom_razon_social',
    ];

    public function employeeDetails()
    {
        return $this->hasMany(EmployeeDetail::class, 'razon_social_id');
    }
}
