<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Services\Biometrics\TemplateDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeFingerprintDeleteController extends Controller
{
    public function __construct(private readonly TemplateDeletionService $templateDeletionService)
    {
    }

    public function destroy(Employee $employee, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fingerprint_id' => ['nullable', 'integer'],
        ]);

        $fingerprintsQuery = EmployeeFingerprint::query()
            ->where('employee_id', $employee->id)
            ->fingerprint();

        if (! empty($validated['fingerprint_id'])) {
            $fingerprintsQuery->whereKey((int) $validated['fingerprint_id']);
        } elseif (Schema::hasColumn('employee_fingerprints', 'is_active')) {
            $fingerprintsQuery->where('is_active', true);
        }

        $fingerprintColumns = ['id', 'vendor_template_id', 'enrolment_type'];
        if (Schema::hasColumn('employee_fingerprints', 'template_vendor')) {
            $fingerprintColumns[] = 'template_vendor';
        }
        if (Schema::hasColumn('employee_fingerprints', 'template_source')) {
            $fingerprintColumns[] = 'template_source';
        }

        $fingerprints = $fingerprintsQuery->select($fingerprintColumns)->get();

        $fingerprintIds = $fingerprints
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $deletedCount = count($fingerprintIds);

        DB::transaction(function () use ($employee, $fingerprints, $fingerprintIds): void {
            $deletedAt = now();

            foreach ($fingerprints as $fingerprint) {
                $this->templateDeletionService->recordForTemplate(
                    template: $fingerprint,
                    employeeId: (int) $employee->id,
                    deletedAt: $deletedAt,
                );
            }

            if ($fingerprintIds !== []) {
                EmployeeFingerprint::query()->whereIn('id', $fingerprintIds)->delete();
            }

            $employee->refreshFingerprintFlag();
        });

        $request->attributes->set('biometric_fingerprint_ids', $fingerprintIds);
        $request->attributes->set('biometric_deleted_count', $deletedCount);
        $request->attributes->set('biometric_deleted_employee_id', (int) $employee->id);

        return response()
            ->json([
                'ok' => true,
                'deleted_count' => $deletedCount,
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
}
