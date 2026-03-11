<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeStatusChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'company_id',
        'fortia_employee_id',
        'old_status',
        'new_status',
        'changed_at',
        'source',
        'meta',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'meta' => 'array',
    ];
}
