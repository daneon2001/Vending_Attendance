<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeriodoPago extends Model
{
    use HasFactory;

    protected $table = 'periodos_pago';

    protected $fillable = [
        'cla_periodo_pago',
        'nom_periodo_pago',
    ];

    public function employeeDetails()
    {
        return $this->hasMany(EmployeeDetail::class, 'periodo_pago_id');
    }
}
