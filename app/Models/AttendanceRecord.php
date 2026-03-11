<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $table = 'attendance_logs';

    public const STATUS_VALIDA = 'valida';
    public const STATUS_ANULADA = 'anulada';
    public const STATUS_CORREGIDA = 'corregida';

    public const SOURCE_SYNC = 'sync';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_API = 'api';

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
        'ingested_at_utc',
        'ingest_ip',
        'device_serial',
        'auth_key_id',
        'request_id',
        'sent_to_fortia_at',
        'fortia_status',
        'fortia_response_payload',
        'status',
        'raw_payload',
        'integrity_hash',
        'integrity_previous_hash',
        'integrity_hash_version',
        'integrity_verified_at',
    ];

    protected $casts = [
        'log_date' => 'datetime',
        'annulled_at' => 'datetime',
        'ingested_at_utc' => 'datetime',
        'sent_to_fortia_at' => 'datetime',
        'integrity_verified_at' => 'datetime',
        'fortia_response_payload' => 'array',
        'raw_payload' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function clock()
    {
        return $this->belongsTo(Clock::class, 'device_id');
    }

    public function audits()
    {
        return $this->hasMany(AttendanceAudit::class, 'attendance_log_id');
    }

    public function annulledBy()
    {
        return $this->belongsTo(User::class, 'annulled_by_user_id');
    }

    public function scopeWithinDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $builder) => $builder->where('log_date', '>=', $from.' 00:00:00'))
            ->when($to, fn (Builder $builder) => $builder->where('log_date', '<=', $to.' 23:59:59'));
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        if (! $status) {
            return $query;
        }

        return $query->where('attendance_status', $status);
    }

    public function scopeBySource(Builder $query, ?string $source): Builder
    {
        if (! $source) {
            return $query;
        }

        return $query->where('source', $source);
    }
}
