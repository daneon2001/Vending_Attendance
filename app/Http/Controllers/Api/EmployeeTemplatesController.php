<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeTemplatesSyncRequest;
use App\Models\EmployeeFingerprint;
use App\Models\EmployeeTemplateDeletion;
use App\Services\Biometrics\AllowedBiometricCandidates;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class EmployeeTemplatesController extends Controller
{
    public function __construct(protected AllowedBiometricCandidates $allowedCandidates)
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

        $baseTombstonesQuery = EmployeeTemplateDeletion::query()
            ->whereNotNull('vendor_template_id')
            ->whereNotNull('deleted_at');

        if (Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
            $normalizedBiometricType = strtoupper((string) $biometricType);

            if ($normalizedBiometricType !== '' && $normalizedBiometricType !== 'ALL') {
                $baseTombstonesQuery->where('biometric_type', $normalizedBiometricType);
            }
        }

        if ($locationId || $status !== 'all') {
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

        $tombstones = $tombstonesQuery
            ->orderBy('deleted_at')
            ->get($tombstoneColumns)
            ->map(fn (EmployeeTemplateDeletion $fingerprint): array => [
                'vendor' => (string) ($fingerprint->vendor ?: 'digitalpersona'),
                'biometric_type' => (string) ($fingerprint->biometric_type ?: 'FINGERPRINT'),
                'vendor_template_id' => (string) $fingerprint->vendor_template_id,
                'deleted_at' => optional($fingerprint->deleted_at)?->toIso8601String(),
            ])
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

        return response()->json([
            'version' => ($versionTime ?? now())->format('YmdHis'),
            'data' => $templates->map(function (EmployeeFingerprint $fingerprint): array {
                $biometricType = strtoupper((string) ($fingerprint->enrolment_type ?: 'FINGERPRINT'));

                return [
                    'employee_id' => (int) $fingerprint->employee_id,
                    'vendor' => 'digitalpersona',
                    'vendor_template_id' => (string) $fingerprint->vendor_template_id,
                    'biometric_type' => $biometricType,
                    'template_format' => (string) ($fingerprint->template_format ?: 'DPFP_PROPRIETARY'),
                    'template_b64' => (string) $fingerprint->template_b64,
                    'hash_sha256' => $this->hashTemplate((string) $fingerprint->template_b64),
                    'location_id' => $fingerprint->employee?->base_location_id ? (int) $fingerprint->employee->base_location_id : null,
                    'can_check_all_branches' => (bool) ($fingerprint->employee?->can_check_all_branches ?? false),
                    'sync_ready' => $biometricType === EmployeeFingerprint::TYPE_FACE
                        ? (bool) ($fingerprint->employee?->face_sync_ready ?? false)
                        : true,
                    'face_status' => $biometricType === EmployeeFingerprint::TYPE_FACE
                        ? (string) ($fingerprint->employee?->face_status ?? 'none')
                        : null,
                    'face_enabled' => $biometricType === EmployeeFingerprint::TYPE_FACE
                        ? (bool) ($fingerprint->employee?->face_enabled ?? false)
                        : null,
                    'face_samples_count' => $biometricType === EmployeeFingerprint::TYPE_FACE
                        ? (int) ($fingerprint->employee?->face_samples_count ?? 0)
                        : null,
                    'face_template_version' => $biometricType === EmployeeFingerprint::TYPE_FACE
                        ? ($fingerprint->employee?->face_template_version ?? null)
                        : null,
                    'face_quality_score' => $biometricType === EmployeeFingerprint::TYPE_FACE
                        ? (is_numeric($fingerprint->employee?->face_quality_score)
                            ? (int) $fingerprint->employee?->face_quality_score
                            : null)
                        : null,
                    'face_updated_at' => $biometricType === EmployeeFingerprint::TYPE_FACE
                        ? optional($fingerprint->employee?->face_updated_at)?->toIso8601String()
                        : null,
                    'captured_at' => optional($fingerprint->performed_at)?->toIso8601String(),
                    'updated_at' => optional($fingerprint->updated_at)?->toIso8601String(),
                ];
            })->values(),
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

    private function hashTemplate(string $templateB64): string
    {
        $decoded = base64_decode($templateB64, true);
        if ($decoded === false) {
            $decoded = $templateB64;
        }

        return hash('sha256', $decoded);
    }
}
