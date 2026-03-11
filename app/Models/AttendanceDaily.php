<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceDaily extends Model
{
    use HasFactory;

    protected $table = 'attendance_dailies';

    protected $fillable = [
        'employee_id',
        'company_id',
        'location_id',
        'work_date',
        'first_check_in_at',
        'last_check_out_at',
        'total_logs',
        'total_in',
        'total_out',
        'late_minutes',
        'is_absence',
        'consolidation_status',
        'consolidated_payload',
        'consolidated_at',
    ];

    protected $casts = [
        'work_date' => 'date',
        'first_check_in_at' => 'datetime',
        'last_check_out_at' => 'datetime',
        'is_absence' => 'boolean',
        'consolidated_payload' => 'array',
        'consolidated_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
