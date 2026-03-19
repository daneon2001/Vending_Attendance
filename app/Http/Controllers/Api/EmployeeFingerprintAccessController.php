<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EmployeeFingerprintAccessController extends Controller
{
    public function index(Request $request, Employee $employee): JsonResponse
    {
        $fingerprintsQuery = $employee->fingerprintTemplates()
            ->select([
                'id',
                'employee_id',
                'enrolment_type',
                'status',
                'created_at',
            ]);

        if (Schema::hasColumn('employee_fingerprints', 'quality')) {
            $fingerprintsQuery->addSelect('quality');
        }

        $rawFingerprints = $fingerprintsQuery->get();
        $fingerprintIds = $rawFingerprints->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $fingerprints = $rawFingerprints
            ->map(fn (EmployeeFingerprint $fingerprint) => $this->transformMetadata($fingerprint))
            ->values();

        $request->attributes->set('biometric_fingerprint_ids', $fingerprintIds);

        return response()->json([
            'ok' => true,
            'data' => $fingerprints,
        ]);
    }

    public function templates(Request $request, Employee $employee): JsonResponse
    {
        $fingerprintsQuery = $employee->fingerprintTemplates()
            ->select([
                'id',
                'employee_id',
                'vendor_template_id',
                'template_b64',
                'template_format',
                'enrolment_type',
                'status',
                'device_serial',
                'performed_at',
                'created_at',
                'updated_at',
            ]);

        if (Schema::hasColumn('employee_fingerprints', 'quality')) {
            $fingerprintsQuery->addSelect('quality');
        }

        $rawFingerprints = $fingerprintsQuery->get();
        $fingerprintIds = $rawFingerprints->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $fingerprints = $rawFingerprints
            ->map(fn (EmployeeFingerprint $fingerprint) => $this->transformTemplate($fingerprint))
            ->values();

        $request->attributes->set('biometric_fingerprint_ids', $fingerprintIds);

        return response()
            ->json([
                'ok' => true,
                'data' => $fingerprints,
            ])
            ->withHeaders($this->noStoreHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMetadata(EmployeeFingerprint $fingerprint): array
    {
        $quality = $fingerprint->quality ?? null;

        return [
            'id' => (int) $fingerprint->id,
            'type' => $fingerprint->enrolment_type ?: ($fingerprint->status ?: 'FINGERPRINT'),
            'quality' => is_numeric($quality) ? (int) $quality : null,
            'created_at' => optional($fingerprint->created_at)->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTemplate(EmployeeFingerprint $fingerprint): array
    {
        $quality = $fingerprint->quality ?? null;

        return [
            'id' => (int) $fingerprint->id,
            'type' => $fingerprint->enrolment_type ?: ($fingerprint->status ?: 'FINGERPRINT'),
            'quality' => is_numeric($quality) ? (int) $quality : null,
            'template_b64' => $fingerprint->template_b64,
            'vendor_template_id' => $fingerprint->vendor_template_id,
            'template_format' => $fingerprint->template_format,
            'status' => $fingerprint->status,
            'device_serial' => $fingerprint->device_serial,
            'performed_at' => optional($fingerprint->performed_at)->toISOString(),
            'created_at' => optional($fingerprint->created_at)->toISOString(),
            'updated_at' => optional($fingerprint->updated_at)->toISOString(),
        ];
    }
}
