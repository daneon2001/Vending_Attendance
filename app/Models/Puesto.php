<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Puesto extends Model
{
    use HasFactory;

    protected $table = 'puestos';

    protected $fillable = [
        'cla_puesto',
        'nom_puesto',
    ];

    public function employeeDetails()
    {
        return $this->hasMany(EmployeeDetail::class);
    }
}
