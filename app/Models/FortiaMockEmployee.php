<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FortiaMockEmployee extends Model
{
    use HasFactory;

    protected $connection = 'fortia_mock';

    protected $table = 'fortia_employees';

    protected $fillable = [
        'company_id',
        'company_name',
        'employee_id',
        'name',
        'last_name',
        'second_last_name',
        'status',
        'base_location_id',
        'base_location_name',
        'department_id',
        'department_name',
        'rfc',
        'imss_number',
        'curp',
        'email_company',
        'created_at',
        'updated_at',
    ];
}
