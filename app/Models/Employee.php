<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Employee extends Model
{
    use HasFactory;

    public const CHECK_SCOPE_HOME_ONLY = 'HOME_ONLY';
    public const CHECK_SCOPE_ANY_BRANCH = 'ANY_BRANCH';
    public const CHECK_SCOPE_SELECTED_BRANCHES = 'SELECTED_BRANCHES';

    private const ACTIVE_TEMPLATE_STATUSES = ['enrolled', 'active'];

    private const SYNC_READY_FACE_STATUSES = ['enrolled', 'ready'];

    protected $fillable = [
        'fortia_employee_id',
        'company_id',
        'company_name',
        'base_location_id',
        'base_location_name',
        'can_check_all_branches',
        'check_scope',
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
        'has_face_enrollment',
        'face_status',
        'face_samples_count',
        'face_template_version',
        'face_updated_at',
        'face_enabled',
        'face_quality_score',
        'face_meta',
        'email_company',
    ];

    protected $casts = [
        'has_fingerprint' => 'boolean',
        'has_face_enrollment' => 'boolean',
        'face_enabled' => 'boolean',
        'face_samples_count' => 'integer',
        'face_updated_at' => 'datetime',
        'face_quality_score' => 'integer',
        'face_meta' => 'array',
        'can_check_all_branches' => 'boolean',
    ];

    protected $appends = [
        'fingerprint_status',
        'face_sync_ready',
        'resolved_check_scope',
    ];

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function detail()
    {
        return $this->hasOne(EmployeeDetail::class);
    }

    public function details()
    {
        return $this->hasOne(EmployeeDetail::class);
    }

    public function company()
    {
        foreach (['fortia_company_id', 'external_id', 'legacy_code', 'code'] as $ownerKey) {
            if (Schema::hasColumn('companies', $ownerKey)) {
                return $this->belongsTo(Company::class, 'company_id', $ownerKey);
            }
        }

        return $this->belongsTo(Company::class, 'company_id');
    }

    public function fingerprints()
    {
        return $this->hasMany(EmployeeFingerprint::class);
    }

    public function fingerprintTemplates()
    {
        return $this->hasMany(EmployeeFingerprint::class)
            ->fingerprint();
    }

    public function faceTemplates()
    {
        return $this->hasMany(EmployeeFingerprint::class)
            ->face();
    }

    public function unit()
    {
        return $this->baseLocation();
    }

    public function baseLocation()
    {
        foreach (['fortia_location_id', 'external_id', 'legacy_code', 'code'] as $ownerKey) {
            if (Schema::hasColumn('locations', $ownerKey)) {
                return $this->belongsTo(Location::class, 'base_location_id', $ownerKey);
            }
        }

        return $this->belongsTo(Location::class, 'base_location_id');
    }

    public function allowedLocations()
    {
        return $this->belongsToMany(
            Location::class,
            'employee_allowed_locations',
            'employee_id',
            'location_id'
        );
    }

    public function getFingerprintStatusAttribute(): string
    {
        $fingerprints = $this->resolveLoadedBiometrics('FINGERPRINT');

        if ($fingerprints === null) {
            return $this->has_fingerprint ? 'enrolled' : 'none';
        }

        if ($fingerprints->first(fn (EmployeeFingerprint $template) => in_array(
            strtolower((string) $template->status),
            self::ACTIVE_TEMPLATE_STATUSES,
            true
        ))) {
            return 'enrolled';
        }

        if ($fingerprints->firstWhere('status', 'pending_delete')) {
            return 'pending_delete';
        }

        return 'none';
    }

    public function refreshFingerprintFlag(): void
    {
        $hasFingerprint = $this->fingerprintTemplates()
            ->active()
            ->whereNull('deleted_at')
            ->exists();

        $updates = [
            'has_fingerprint' => $hasFingerprint,
        ];

        if (! $hasFingerprint) {
            $updates['updated_at'] = now();
        }

        if ($this->has_fingerprint !== $hasFingerprint) {
            $this->forceFill($updates)->saveQuietly();
        }
    }

    public function getFaceSyncReadyAttribute(): bool
    {
        return (bool) $this->has_face_enrollment
            && (bool) $this->face_enabled
            && in_array($this->normalizedFaceStatus(), self::SYNC_READY_FACE_STATUSES, true)
            && (int) ($this->face_samples_count ?? 0) > 0;
    }

    public function scopeFaceSyncReady(Builder $query): Builder
    {
        return $query
            ->where('has_face_enrollment', true)
            ->where('face_enabled', true)
            ->whereIn('face_status', self::SYNC_READY_FACE_STATUSES)
            ->where('face_samples_count', '>', 0);
    }

    public function markFaceEnrolled(array $attributes = []): void
    {
        $currentMeta = $this->face_meta;
        if (! is_array($currentMeta)) {
            $currentMeta = [];
        }

        $hasCurrentEnrollment = (bool) $this->has_face_enrollment;
        $status = strtolower((string) ($attributes['face_status'] ?? $this->face_status ?? ''));
        $faceEnabled = array_key_exists('face_enabled', $attributes)
            ? (bool) $attributes['face_enabled']
            : ($hasCurrentEnrollment ? (bool) $this->face_enabled : true);

        if (! in_array($status, ['disabled', 'review_required', 'pending', 'ready', 'enrolled'], true)) {
            $status = $faceEnabled ? 'enrolled' : 'disabled';
        }

        if ($status === 'disabled') {
            $faceEnabled = false;
        }

        $samplesCount = (int) ($attributes['face_samples_count'] ?? $this->face_samples_count ?? 0);
        if ($samplesCount < 1) {
            $samplesCount = 1;
        }

        $meta = array_filter(array_merge(
            $currentMeta,
            is_array($attributes['face_meta'] ?? null) ? $attributes['face_meta'] : [],
            [
                'last_vendor_template_id' => $attributes['vendor_template_id'] ?? ($currentMeta['last_vendor_template_id'] ?? null),
                'last_template_format' => $attributes['template_format'] ?? ($currentMeta['last_template_format'] ?? null),
                'last_device_serial' => $attributes['device_serial'] ?? ($currentMeta['last_device_serial'] ?? null),
                'last_enrolment_type' => 'FACE',
            ]
        ), fn ($value) => $value !== null && $value !== '');

        $this->forceFill([
            'has_face_enrollment' => true,
            'face_enabled' => $faceEnabled,
            'face_status' => $status,
            'face_samples_count' => $samplesCount,
            'face_template_version' => $attributes['face_template_version'] ?? $this->face_template_version,
            'face_updated_at' => $attributes['face_updated_at'] ?? now(),
            'face_quality_score' => $attributes['face_quality_score'] ?? $this->face_quality_score,
            'face_meta' => $meta !== [] ? $meta : null,
        ])->saveQuietly();
    }

    public function refreshFaceEnrollmentState(): void
    {
        $latestTemplate = $this->faceTemplates()
            ->active()
            ->whereNull('deleted_at')
            ->orderByDesc('performed_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestTemplate) {
            $currentMeta = $this->face_meta;
            if (! is_array($currentMeta)) {
                $currentMeta = [];
            }

            $currentMeta['last_face_cleared_at'] = now()->toIso8601String();

            $this->forceFill([
                'has_face_enrollment' => false,
                'face_status' => 'none',
                'face_samples_count' => 0,
                'face_template_version' => null,
                'face_updated_at' => null,
                'face_enabled' => false,
                'face_quality_score' => null,
                'face_meta' => $currentMeta,
            ])->saveQuietly();

            return;
        }

        $this->markFaceEnrolled([
            'face_enabled' => (bool) $this->face_enabled,
            'face_status' => $this->normalizedFaceStatus() === 'none'
                ? 'enrolled'
                : $this->normalizedFaceStatus(),
            'face_samples_count' => max(1, (int) ($this->face_samples_count ?? 0)),
            'face_template_version' => $this->face_template_version,
            'face_updated_at' => $latestTemplate->performed_at ?? $latestTemplate->updated_at ?? now(),
            'face_quality_score' => $this->face_quality_score,
            'vendor_template_id' => $latestTemplate->vendor_template_id,
            'template_format' => $latestTemplate->template_format,
            'device_serial' => $latestTemplate->device_serial,
        ]);
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

    public function getResolvedCheckScopeAttribute(): string
    {
        $checkScope = strtoupper(trim((string) ($this->check_scope ?? '')));

        if (in_array($checkScope, [
            self::CHECK_SCOPE_HOME_ONLY,
            self::CHECK_SCOPE_ANY_BRANCH,
            self::CHECK_SCOPE_SELECTED_BRANCHES,
        ], true)) {
            return $checkScope;
        }

        return (bool) $this->can_check_all_branches
            ? self::CHECK_SCOPE_ANY_BRANCH
            : self::CHECK_SCOPE_HOME_ONLY;
    }

    public function isHomeOnlyScope(): bool
    {
        return $this->resolved_check_scope === self::CHECK_SCOPE_HOME_ONLY;
    }

    public function isAnyBranchScope(): bool
    {
        return $this->resolved_check_scope === self::CHECK_SCOPE_ANY_BRANCH;
    }

    public function isSelectedBranchesScope(): bool
    {
        return $this->resolved_check_scope === self::CHECK_SCOPE_SELECTED_BRANCHES;
    }

    public function visibleEmployeeKey(): ?string
    {
        foreach (['fortia_employee_id', 'employee_code', 'code', 'clave_empleado'] as $field) {
            $value = $this->getAttribute($field);

            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    public function syncCheckScope(array $allowedLocationIds = []): void
    {
        $scope = $this->resolved_check_scope;

        if ($scope === self::CHECK_SCOPE_ANY_BRANCH) {
            $this->forceFill([
                'can_check_all_branches' => true,
                'check_scope' => self::CHECK_SCOPE_ANY_BRANCH,
            ])->save();

            $this->allowedLocations()->sync([]);

            return;
        }

        if ($scope === self::CHECK_SCOPE_SELECTED_BRANCHES) {
            $this->forceFill([
                'can_check_all_branches' => false,
                'check_scope' => self::CHECK_SCOPE_SELECTED_BRANCHES,
            ])->save();

            $this->allowedLocations()->sync(collect($allowedLocationIds)
                ->filter(fn ($id) => filled($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all());

            return;
        }

        $this->forceFill([
            'can_check_all_branches' => false,
            'check_scope' => self::CHECK_SCOPE_HOME_ONLY,
        ])->save();

        $this->allowedLocations()->sync([]);
    }

    private function normalizedFaceStatus(): string
    {
        $status = strtolower((string) ($this->face_status ?? 'none'));

        return $status !== '' ? $status : 'none';
    }

    private function resolveLoadedBiometrics(string $type)
    {
        if ($this->relationLoaded('fingerprintTemplates') && $type === 'FINGERPRINT') {
            return $this->fingerprintTemplates;
        }

        if ($this->relationLoaded('faceTemplates') && $type === 'FACE') {
            return $this->faceTemplates;
        }

        if (! $this->relationLoaded('fingerprints')) {
            return null;
        }

        return $this->fingerprints->filter(function (EmployeeFingerprint $template) use ($type): bool {
            $enrolmentType = strtoupper((string) $template->enrolment_type);

            if ($type === 'FINGERPRINT') {
                return $enrolmentType === '' || $enrolmentType === 'FINGERPRINT';
            }

            return $enrolmentType === 'FACE';
        })->values();
    }
}
