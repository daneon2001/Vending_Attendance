<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeTemplatesSyncRequest;
use App\Models\EmployeeFingerprint;
use App\Models\EmployeeTemplateDeletion;
use App\Services\Biometrics\AllowedBiometricCandidates;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

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

        $tombstones = $tombstonesQuery
            ->orderBy('deleted_at')
            ->get(['vendor', 'vendor_template_id', 'deleted_at'])
            ->map(fn (EmployeeTemplateDeletion $fingerprint): array => [
                'vendor' => (string) ($fingerprint->vendor ?: 'digitalpersona'),
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
