<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use HasFactory;

    // Si la tabla se llama "empleados", Laravel la infiere solo.
    // Si le pusiste otro nombre, descomenta y ajusta:
    // protected $table = 'empleados';

        protected $fillable = [
            'user_id',
            'company_id',
            'num_empleado',
            'employee_code',
            'nombre',
            'apellidos',
            'status',
        ];


    // Relaciones básicas (opcional pero recomendado)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
