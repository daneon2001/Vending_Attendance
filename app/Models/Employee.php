<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'fortia_employee_id',
        'company_id',
        'company_name',
        'base_location_id',
        'base_location_name',
        'can_check_all_branches',
        'department_id',
        'department_name',
        'name',
        'last_name',
        'second_last_name',
        'full_name',
        'status',
        'rfc',
        'imss_number',
        'curp',
        'has_fingerprint',
        'email_company',
    ];

    protected $casts = [
        'has_fingerprint' => 'boolean',
        'can_check_all_branches' => 'boolean',
    ];

    protected $appends = [
        'fingerprint_status',
    ];

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function fingerprints()
    {
        return $this->hasMany(EmployeeFingerprint::class);
    }

    public function unit()
    {
        return $this->belongsTo(Location::class, 'base_location_id');
    }

    public function getFingerprintStatusAttribute(): string
    {
        if (! $this->relationLoaded('fingerprints')) {
            return $this->has_fingerprint ? 'enrolled' : 'none';
        }

        $fingerprints = $this->fingerprints;

        if ($fingerprints->firstWhere('status', 'enrolled')) {
            return 'enrolled';
        }

        if ($fingerprints->firstWhere('status', 'pending_delete')) {
            return 'pending_delete';
        }

        return 'none';
    }

    public function refreshFingerprintFlag(): void
    {
        $hasFingerprint = $this->fingerprints()
            ->where('status', 'enrolled')
            ->exists();

        if ($this->has_fingerprint !== $hasFingerprint) {
            $this->forceFill(['has_fingerprint' => $hasFingerprint])->saveQuietly();
        }
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $query = $this->newQuery();

        if (is_numeric($value)) {
            return $query
                ->whereKey((int) $value)
                ->orWhere('fortia_employee_id', (int) $value)
                ->first();
        }

        return $query->where($this->getRouteKeyName(), $value)->first();
    }
}
