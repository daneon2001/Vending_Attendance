<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'log_id',
        'employee_id',
        'fortia_employee_id',
        'company_id',
        'location_id',
        'device_id',
        'log_date',
        'log_type',
        'function_int',
        'function_str',
        'sent_to_fortia_at',
        'fortia_status',
        'fortia_response_payload',
        'status',
        'raw_payload',
    ];

    protected $casts = [
        'log_date' => 'datetime',
        'sent_to_fortia_at' => 'datetime',
        'fortia_response_payload' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
