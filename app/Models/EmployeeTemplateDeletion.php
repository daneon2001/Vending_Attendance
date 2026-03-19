<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeTemplateDeletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor',
        'biometric_type',
        'vendor_template_id',
        'employee_id',
        'deleted_at',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public $timestamps = true;

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
