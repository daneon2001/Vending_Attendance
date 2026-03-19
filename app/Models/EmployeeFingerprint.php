<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmployeeFingerprint extends Model
{
    use HasFactory;

    public const TYPE_FINGERPRINT = 'FINGERPRINT';

    public const TYPE_FACE = 'FACE';

    public const ACTIVE_STATUSES = ['enrolled', 'active'];

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

    public function scopeFingerprint(Builder $query): Builder
    {
        return $query->where(function (Builder $fingerprints): void {
            $fingerprints->whereNull('enrolment_type')
                ->orWhere('enrolment_type', self::TYPE_FINGERPRINT);
        });
    }

    public function scopeFace(Builder $query): Builder
    {
        return $query->where('enrolment_type', self::TYPE_FACE);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
