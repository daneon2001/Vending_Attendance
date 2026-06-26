<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeTemplatesSyncRequest;
use App\Models\Employee;
use App\Models\EmployeeFaceTemplate;
use App\Models\EmployeeFingerprint;
use App\Models\EmployeeScopeDeletion;
use App\Models\EmployeeTemplateDeletion;
use App\Models\Location;
use App\Services\Biometrics\AllowedBiometricCandidates;
use App\Services\Biometrics\TemplateMetadataResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeTemplatesController extends Controller
{
    public function __construct(
        protected AllowedBiometricCandidates $allowedCandidates,
        protected TemplateMetadataResolver $templateMetadataResolver
    )
    {
    }

    public function index(EmployeeTemplatesSyncRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $since = isset($validated['since']) ? $this->parseSince($validated['since']) : null;
        $requestedLocationId = null;
        if (array_key_exists('location_id', $validated) && $validated['location_id'] !== null) {
            $requestedLocationId = $this->resolveLocationId((int) $validated['location_id']);
            if ($requestedLocationId === null) {
                return response()->json([
                    'message' => 'The selected location id is invalid.',
                    'errors' => [
                        'location_id' => ['The selected location id is invalid.'],
                    ],
                ], 422);
            }
        }
        $locationId = $this->shouldUseFullEmployeeBiometricSync() ? null : $requestedLocationId;
        $status = $validated['status'] ?? 'active';
        $biometricType = $validated['biometric_type'] ?? null;

        if ($this->isFaceTemplateRequest($biometricType)) {
            return $this->indexFaceTemplates($since, $locationId, $status, $biometricType);
        }

        $baseTemplatesQuery = $this->allowedCandidates->getAllowedCandidatesQuery(
            $locationId,
            $biometricType,
            $status
        );

        $templatesQuery = clone $baseTemplatesQuery;
        if ($since) {
            $templatesQuery->where('updated_at', '>', $since);
        }

        $templates = $templatesQuery
            ->orderBy('updated_at')
            ->get();

        $allowedLocationMap = $this->loadAllowedLocationMap(
            $templates->pluck('employee_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()
        );

        $baseTombstonesQuery = EmployeeTemplateDeletion::query()
            ->whereNotNull('vendor_template_id')
            ->whereNotNull('deleted_at');

        if (Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
            $normalizedBiometricType = strtoupper((string) $biometricType);

            if ($normalizedBiometricType !== '' && $normalizedBiometricType !== 'ALL') {
                $baseTombstonesQuery->where('biometric_type', $normalizedBiometricType);
            }
        }

        if (Schema::hasColumn('employee_template_deletions', 'scope_location_id')) {
            if ($locationId !== null) {
                $baseTombstonesQuery->where(function ($query) use ($locationId, $status): void {
                    $query->where('scope_location_id', $locationId)
                        ->orWhere(function ($globalScopeQuery) use ($locationId, $status): void {
                            $globalScopeQuery->whereNull('scope_location_id')
                                ->where(function ($employeeQuery) use ($locationId, $status): void {
                                    $employeeQuery->whereNull('employee_id')
                                        ->orWhereIn(
                                            'employee_id',
                                            $this->allowedCandidates
                                                ->getAllowedEmployeesQuery($locationId, $status)
                                                ->select('id')
                                        );

                                    if (Schema::hasTable('employee_scope_deletions')) {
                                        $employeeQuery->orWhereIn(
                                            'employee_id',
                                            EmployeeScopeDeletion::query()
                                                ->where('scope_location_id', $locationId)
                                                ->select('employee_id')
                                        );
                                    }
                                });
                        });
                });
            } else {
                $baseTombstonesQuery->whereNull('scope_location_id');
            }
        } elseif ($locationId || $status !== 'all') {
            $baseTombstonesQuery->where(function ($query) use ($locationId, $status): void {
                $query->whereNull('employee_id')
                    ->orWhereIn(
                        'employee_id',
                        $this->allowedCandidates
                            ->getAllowedEmployeesQuery($locationId, $status)
                            ->select('id')
                    );
            });
        }

        $tombstonesQuery = clone $baseTombstonesQuery;
        if ($since) {
            $tombstonesQuery->where('deleted_at', '>', $since);
        }

        $activeVendorTemplateIds = $templates
            ->pluck('vendor_template_id')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();

        if ($activeVendorTemplateIds->isNotEmpty()) {
            $tombstonesQuery->whereNotIn('vendor_template_id', $activeVendorTemplateIds->all());
        }

        $tombstoneColumns = ['vendor', 'vendor_template_id', 'deleted_at'];
        if (Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
            $tombstoneColumns[] = 'biometric_type';
        }
        if (Schema::hasColumn('employee_template_deletions', 'template_source')) {
            $tombstoneColumns[] = 'template_source';
        }
        if (Schema::hasColumn('employee_template_deletions', 'scope_location_id')) {
            $tombstoneColumns[] = 'scope_location_id';
        }

        $tombstones = $tombstonesQuery
            ->orderBy('deleted_at')
            ->get($tombstoneColumns)
            ->map(function (EmployeeTemplateDeletion $fingerprint): array {
                $biometricType = (string) ($fingerprint->biometric_type ?: EmployeeFingerprint::TYPE_FINGERPRINT);
                $defaults = $this->templateMetadataResolver->defaultsFor($biometricType);

                return [
                    'vendor' => (string) ($fingerprint->vendor ?: $defaults['vendor']),
                    'biometric_type' => $biometricType,
                    'template_source' => (string) ($fingerprint->template_source ?: $defaults['source']),
                    'vendor_template_id' => (string) $fingerprint->vendor_template_id,
                    'deleted_at' => optional($fingerprint->deleted_at)?->toIso8601String(),
                ];
            })
            ->groupBy('vendor_template_id')
            ->map(fn ($items) => collect($items)->sortByDesc('deleted_at')->first())
            ->values();

        $maxUpdatedAt = (clone $baseTemplatesQuery)->max('updated_at');
        $maxDeletedAt = (clone $baseTombstonesQuery)->max('deleted_at');
        $maxSince = $since?->copy();

        $versionTime = collect([$maxUpdatedAt, $maxDeletedAt, $maxSince])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sort()
            ->last();

        $this->allowedCandidates->logAllowedCandidates(
            'onprem.biometric_candidates.resolved',
            $locationId,
            $biometricType,
            $status
        );

        $templatePayloads = $templates
            ->map(function (EmployeeFingerprint $fingerprint) use ($allowedLocationMap): array {
                $employeeId = (int) $fingerprint->employee_id;

                $data = $this->transformTemplate(
                    $fingerprint,
                    $allowedLocationMap[$employeeId] ?? []
                );

                return [
                    'data' => $data,
                    'legacy' => $this->transformLegacyFingerprint($fingerprint, $data),
                ];
            })
            ->values();

        return response()->json([
            'version' => ($versionTime ?? now())->format('YmdHis'),
            'data' => $templatePayloads->pluck('data')->values(),
            'huellas' => $templatePayloads->pluck('legacy')->filter()->values(),
            'tombstones' => $tombstones,
        ]);
    }

    private function parseSince(string $value): ?Carbon
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{14}$/', $raw) === 1) {
            return Carbon::createFromFormat('YmdHis', $raw);
        }

        return Carbon::parse($raw);
    }

    /**
     * @param  array<int, int>  $allowedLocationIds
     * @return array<string, mixed>
     */
    private function transformTemplate(EmployeeFingerprint $fingerprint, array $allowedLocationIds = []): array
    {
        $metadata = $this->templateMetadataResolver->fromTemplate($fingerprint);
        $biometricType = $metadata['biometric_type'];
        $employee = $fingerprint->employee;

        return [
            'employee_id' => (int) $fingerprint->employee_id,
            'vendor' => $metadata['vendor'],
            'vendor_template_id' => (string) $fingerprint->vendor_template_id,
            'biometric_type' => $biometricType,
            'template_source' => $metadata['source'],
            'template_format' => (string) ($fingerprint->template_format ?: 'DPFP_PROPRIETARY'),
            'template_b64' => (string) $fingerprint->template_b64,
            'hash_sha256' => $this->hashTemplate((string) $fingerprint->template_b64),
            'location_id' => $employee?->base_location_id ? (int) $employee?->base_location_id : null,
            'base_location_id' => $employee?->base_location_id ? (int) $employee?->base_location_id : null,
            'can_check_all_branches' => (bool) ($employee?->can_check_all_branches ?? false),
            'check_scope' => $this->resolveCheckScopeFromEmployee($employee),
            'allowed_location_ids' => $allowedLocationIds,
            'sync_ready' => $biometricType === EmployeeFingerprint::TYPE_FACE
                ? (bool) ($employee?->face_sync_ready ?? false)
                : true,
            'face_status' => $biometricType === EmployeeFingerprint::TYPE_FACE
                ? (string) ($employee?->face_status ?? 'none')
                : null,
            'face_enabled' => $biometricType === EmployeeFingerprint::TYPE_FACE
                ? (bool) ($employee?->face_enabled ?? false)
                : null,
            'face_samples_count' => $biometricType === EmployeeFingerprint::TYPE_FACE
                ? (int) ($employee?->face_samples_count ?? 0)
                : null,
            'face_template_version' => $biometricType === EmployeeFingerprint::TYPE_FACE
                ? ($employee?->face_template_version ?? null)
                : null,
            'face_quality_score' => $biometricType === EmployeeFingerprint::TYPE_FACE
                ? (is_numeric($employee?->face_quality_score)
                    ? (int) $employee?->face_quality_score
                    : null)
                : null,
            'face_updated_at' => $biometricType === EmployeeFingerprint::TYPE_FACE
                ? optional($employee?->face_updated_at)?->toIso8601String()
                : null,
            'captured_at' => optional($fingerprint->performed_at)?->toIso8601String(),
            'updated_at' => optional($fingerprint->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function transformLegacyFingerprint(EmployeeFingerprint $fingerprint, array $data): ?array
    {
        if (($data['biometric_type'] ?? EmployeeFingerprint::TYPE_FINGERPRINT) !== EmployeeFingerprint::TYPE_FINGERPRINT) {
            return null;
        }

        $templateB64 = trim((string) ($fingerprint->template_b64 ?? ''));

        return [
            'employee_id' => $data['employee_id'],
            'vendor_template_id' => $data['vendor_template_id'],
            'template_format' => $data['template_format'],
            'fingerprint' => $this->isLegacyFingerprintContractCompatible(
                $templateB64,
                (string) ($data['template_format'] ?? '')
            ) ? $templateB64 : null,
            'captured_at' => $data['captured_at'],
            'updated_at' => $data['updated_at'],
        ];
    }

    private function isLegacyFingerprintContractCompatible(string $templateB64, string $templateFormat): bool
    {
        if (! $this->isLegacyCompatibleFormat($templateFormat)) {
            return false;
        }

        return $this->isBase64Payload($templateB64);
    }

    private function isLegacyCompatibleFormat(string $templateFormat): bool
    {
        $normalizedFormat = strtoupper(trim($templateFormat));

        if ($normalizedFormat === '') {
            return false;
        }

        $compatibleFormats = collect((array) config('biometrics.fingerprint.legacy_compatible_formats', ['DPFP_PROPRIETARY']))
            ->map(static fn (mixed $format): string => strtoupper(trim((string) $format)))
            ->filter()
            ->values()
            ->all();

        return in_array($normalizedFormat, $compatibleFormats, true);
    }

    private function isBase64Payload(string $payload): bool
    {
        if ($payload === '') {
            return false;
        }

        return base64_decode($payload, true) !== false;
    }

    private function hashTemplate(string $templateB64): string
    {
        $decoded = base64_decode($templateB64, true);
        if ($decoded === false) {
            $decoded = $templateB64;
        }

        return hash('sha256', $decoded);
    }

    private function isFaceTemplateRequest(?string $biometricType): bool
    {
        return in_array(strtoupper(trim((string) $biometricType)), ['FACE', 'FACE_ID'], true);
    }

    private function indexFaceTemplates(?Carbon $since, ?int $locationId, string $status, ?string $requestedBiometricType): JsonResponse
    {
        $responseBiometricType = strtoupper(trim((string) $requestedBiometricType)) === 'FACE' ? 'FACE' : 'FACE_ID';
        $allowedEmployeeIdsQuery = $this->allowedCandidates
            ->getAllowedEmployeesQuery($locationId, $status)
            ->select('id');

        $baseTemplatesQuery = EmployeeFaceTemplate::query()
            ->with('employee')
            ->where('is_active', true)
            ->whereNotNull('template_hash')
            ->where('template_hash', '<>', '')
            ->whereNotNull('embedding_encrypted')
            ->where('embedding_encrypted', '<>', '')
            ->where('model_name', 'FaceRecognitionDotNet')
            ->whereIn('employee_id', $allowedEmployeeIdsQuery);

        $templatesQuery = clone $baseTemplatesQuery;
        if ($since) {
            $templatesQuery->where('updated_at', '>', $since);
        }

        $templates = $templatesQuery
            ->orderBy('updated_at')
            ->get();

        $allowedLocationMap = $this->loadAllowedLocationMap(
            $templates->pluck('employee_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()
        );

        $tombstonesQuery = EmployeeFaceTemplate::query()
            ->where('is_active', false)
            ->whereNotNull('template_hash')
            ->where('template_hash', '<>', '')
            ->whereIn('employee_id', $allowedEmployeeIdsQuery);

        if ($since) {
            $tombstonesQuery->where('updated_at', '>', $since);
        }

        $tombstones = $tombstonesQuery
            ->orderBy('updated_at')
            ->get()
            ->map(function (EmployeeFaceTemplate $template) use ($responseBiometricType): array {
                return [
                    'vendor' => 'facerecognitiondotnet',
                    'biometric_type' => $responseBiometricType,
                    'template_source' => 'FACE_ID',
                    'vendor_template_id' => (string) $template->template_hash,
                    'template_hash' => (string) $template->template_hash,
                    'deleted_at' => optional($template->updated_at)?->toIso8601String(),
                ];
            })
            ->values();

        $versionTime = collect([
            (clone $baseTemplatesQuery)->max('updated_at'),
            (clone EmployeeFaceTemplate::query()
                ->where('is_active', false)
                ->whereNotNull('template_hash')
                ->where('template_hash', '<>', '')
                ->whereIn('employee_id', $allowedEmployeeIdsQuery)
            )->max('updated_at'),
            $since?->copy(),
        ])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sort()
            ->last();

        $this->allowedCandidates->logAllowedEmployees(
            'onprem.face_templates.allowed_employees',
            $locationId,
            $status
        );

        $data = $templates->map(function (EmployeeFaceTemplate $template) use ($allowedLocationMap, $responseBiometricType): array {
            $employee = $template->employee;
            $employeeId = (int) $template->employee_id;

            return [
                'employee_id' => $employeeId,
                'fortia_employee_id' => $template->fortia_employee_id !== null ? (string) $template->fortia_employee_id : null,
                'employee_code' => $template->employee_code !== null ? (string) $template->employee_code : null,
                'vendor' => 'facerecognitiondotnet',
                'vendor_template_id' => (string) $template->template_hash,
                'biometric_type' => $responseBiometricType,
                'template_source' => 'FACE_ID',
                'template_format' => 'FRD_128D_BASE64JSON',
                'template_b64' => (string) $template->embedding_encrypted,
                'hash_sha256' => hash('sha256', (string) $template->embedding_encrypted),
                'location_id' => $employee?->base_location_id ? (int) $employee?->base_location_id : null,
                'base_location_id' => $employee?->base_location_id ? (int) $employee?->base_location_id : null,
                'can_check_all_branches' => (bool) ($employee?->can_check_all_branches ?? false),
                'check_scope' => $this->resolveCheckScopeFromEmployee($employee),
                'allowed_location_ids' => $allowedLocationMap[$employeeId] ?? [],
                'sync_ready' => (bool) ($employee?->face_sync_ready ?? true),
                'face_status' => $employee?->face_status !== null ? (string) $employee?->face_status : 'enrolled',
                'face_enabled' => (bool) ($employee?->face_enabled ?? true),
                'face_samples_count' => (int) ($employee?->face_samples_count ?? 3),
                'face_template_version' => $template->model_version !== null ? (string) $template->model_version : null,
                'face_quality_score' => is_numeric($template->quality_score)
                    ? (int) round(((float) $template->quality_score) * 100)
                    : null,
                'face_updated_at' => optional($employee?->face_updated_at ?: $template->updated_at)?->toIso8601String(),
                'captured_at' => optional($template->captured_at)?->toIso8601String(),
                'updated_at' => optional($template->updated_at)?->toIso8601String(),
                'embedding_encrypted' => (string) $template->embedding_encrypted,
                'template_hash' => (string) $template->template_hash,
                'model_name' => (string) $template->model_name,
                'model_version' => (string) $template->model_version,
                'quality_score' => is_numeric($template->quality_score) ? round((float) $template->quality_score, 4) : null,
                'is_active' => (bool) $template->is_active,
                'synced_at' => optional($template->synced_at)?->toIso8601String(),
                'created_at' => optional($template->created_at)?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'version' => ($versionTime ?? now())->format('YmdHis'),
            'data' => $data,
            'huellas' => [],
            'tombstones' => $tombstones,
        ]);
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<int, int>>
     */
    private function loadAllowedLocationMap(array $employeeIds): array
    {
        if (empty($employeeIds) || ! Schema::hasTable('employee_allowed_locations')) {
            return [];
        }

        $rows = DB::table('employee_allowed_locations')
            ->select('employee_id', 'location_id')
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('employee_id')
            ->orderBy('location_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;
            $locationId = (int) $row->location_id;

            if (! isset($map[$employeeId])) {
                $map[$employeeId] = [];
            }

            $map[$employeeId][] = $locationId;
        }

        return $map;
    }

    private function resolveCheckScopeFromEmployee(?Employee $employee): string
    {
        $checkScope = trim((string) ($employee?->check_scope ?? ''));

        if ($checkScope !== '') {
            return strtoupper($checkScope);
        }

        return (bool) ($employee?->can_check_all_branches ?? false)
            ? 'ANY_BRANCH'
            : 'HOME_ONLY';
    }

    private function resolveLocationId(int $locationId): ?int
    {
        $query = Location::query()->where('id', $locationId);
        if (Schema::hasColumn('locations', 'fortia_location_id')) {
            $query->orWhere('fortia_location_id', $locationId);
        }
        if (Schema::hasColumn('locations', 'code')) {
            $query->orWhere('code', (string) $locationId);
        }

        $resolvedLocationId = $query->value('id');
        if (! is_numeric($resolvedLocationId)) {
            return null;
        }

        return (int) $resolvedLocationId;
    }

    private function shouldUseFullEmployeeBiometricSync(): bool
    {
        return (bool) config('onprem.full_employee_biometric_sync', true);
    }
}
