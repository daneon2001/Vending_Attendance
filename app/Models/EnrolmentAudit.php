<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnrolmentAudit extends Model
{
    use HasFactory;

    public const STATUS_SENT = 'SENT';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_DUPLICATE = 'DUPLICATE';
    public const STATUS_CONFLICT = 'CONFLICT';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'clock_id',
        'enrolment_type',
        'vendor_template_id',
        'device_serial',
        'performed_at',
        'status',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
