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
        'local_id',
        'log_date',
        'log_type',
        'source',
        'attendance_status',
        'adjustment_reason',
        'annulled_at',
        'annulled_by_user_id',
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
        'annulled_at' => 'datetime',
        'sent_to_fortia_at' => 'datetime',
        'fortia_response_payload' => 'array',
        'raw_payload' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
