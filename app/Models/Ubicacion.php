<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ubicacion extends Model
{
    use HasFactory;

    protected $table = 'ubicaciones';

    protected $fillable = [
        'cla_ubicacion',
        'nom_ubicacion',
    ];

    public function employeeDetails()
    {
        return $this->hasMany(EmployeeDetail::class);
    }
}
