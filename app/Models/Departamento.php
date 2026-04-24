<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Departamento extends Model
{
    use HasFactory;

    protected $table = 'departamentos';

    protected $fillable = [
        'cla_depto',
        'nom_departamento',
    ];

    public function employeeDetails()
    {
        return $this->hasMany(EmployeeDetail::class);
    }
}
