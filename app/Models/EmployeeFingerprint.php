<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeFingerprint extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'clock_id',
        'vendor_template_id',
        'template_b64',
        'template_format',
        'enrolment_type',
        'device_serial',
        'status',
        'enrolled_at',
        'performed_at',
        'deleted_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'performed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
