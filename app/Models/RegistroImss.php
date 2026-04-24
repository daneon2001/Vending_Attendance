<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistroImss extends Model
{
    use HasFactory;

    protected $table = 'registros_imss';

    protected $fillable = [
        'cla_reg_imss',
        'nom_reg_imss',
    ];

    public function employeeDetails()
    {
        return $this->hasMany(EmployeeDetail::class, 'registro_imss_id');
    }
}
