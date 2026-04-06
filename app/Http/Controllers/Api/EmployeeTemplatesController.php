<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeTemplatesSyncRequest;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Models\EmployeeScopeDeletion;
use App\Models\EmployeeTemplateDeletion;
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
        $locationId = $validated['location_id'] ?? null;
        $status = $validated['status'] ?? 'active';
        $biometricType = $validated['biometric_type'] ?? null;

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
}