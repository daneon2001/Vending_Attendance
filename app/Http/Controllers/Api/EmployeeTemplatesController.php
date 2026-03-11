<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeTemplatesSyncRequest;
use App\Models\EmployeeFingerprint;
use App\Models\EmployeeTemplateDeletion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class EmployeeTemplatesController extends Controller
{
    public function index(EmployeeTemplatesSyncRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $since = isset($validated['since']) ? $this->parseSince($validated['since']) : null;
        $locationId = $validated['location_id'] ?? null;
        $status = $validated['status'] ?? 'active';

        $baseTemplatesQuery = EmployeeFingerprint::query()
            ->with('employee:id,base_location_id,status')
            ->whereNotNull('vendor_template_id')
            ->whereNotNull('template_b64')
            ->whereNull('deleted_at')
            ->where('status', 'enrolled');

        if ($locationId) {
            $baseTemplatesQuery->whereHas('employee', function ($q) use ($locationId): void {
                $q->where('base_location_id', $locationId);
            });
        }

        if ($status === 'active') {
            $baseTemplatesQuery->whereHas('employee', function ($q): void {
                $q->whereIn('status', ['A', 'active']);
            });
        } elseif ($status === 'inactive') {
            $baseTemplatesQuery->whereHas('employee', function ($q): void {
                $q->whereIn('status', ['B', 'inactive']);
            });
        }

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
                    ->orWhereHas('employee', function ($q) use ($locationId, $status): void {
                        if ($locationId) {
                            $q->where('base_location_id', $locationId);
                        }
                        if ($status === 'active') {
                            $q->whereIn('status', ['A', 'active']);
                        } elseif ($status === 'inactive') {
                            $q->whereIn('status', ['B', 'inactive']);
                        }
                    });
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

        return response()->json([
            'version' => ($versionTime ?? now())->format('YmdHis'),
            'data' => $templates->map(function (EmployeeFingerprint $fingerprint): array {
                return [
                    'employee_id' => (int) $fingerprint->employee_id,
                    'vendor' => 'digitalpersona',
                    'vendor_template_id' => (string) $fingerprint->vendor_template_id,
                    'template_format' => (string) ($fingerprint->template_format ?: 'DPFP_PROPRIETARY'),
                    'template_b64' => (string) $fingerprint->template_b64,
                    'hash_sha256' => $this->hashTemplate((string) $fingerprint->template_b64),
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
