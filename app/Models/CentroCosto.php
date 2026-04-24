<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CentroCosto extends Model
{
    use HasFactory;

    protected $table = 'centros_costo';

    protected $fillable = [
        'cla_centro_costo',
        'nom_centro_costo',
    ];

    public function employeeDetails()
    {
        return $this->hasMany(EmployeeDetail::class, 'centro_costo_id');
    }
}
