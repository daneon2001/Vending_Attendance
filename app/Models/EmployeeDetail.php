<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeDetail extends Model
{
    use HasFactory;

    protected $table = 'employee_details';

    protected $fillable = [
        'employee_id',
        'cla_trab',
        'razon_social_id',
        'registro_imss_id',
        'puesto_id',
        'centro_costo_id',
        'area_id',
        'departamento_id',
        'ubicacion_id',
        'periodo_pago_id',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function razonSocial()
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    public function registroImss()
    {
        return $this->belongsTo(RegistroImss::class, 'registro_imss_id');
    }

    public function puesto()
    {
        return $this->belongsTo(Puesto::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class);
    }

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function periodoPago()
    {
        return $this->belongsTo(PeriodoPago::class, 'periodo_pago_id');
    }
}
