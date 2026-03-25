<?php

namespace App\Services\Biometrics;

use App\Models\EmployeeFingerprint;
use App\Models\EmployeeTemplateDeletion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class TemplateDeletionService
{
    public function __construct(private readonly TemplateMetadataResolver $metadataResolver)
    {
    }

    public function recordForTemplate(
        EmployeeFingerprint $template,
        int $employeeId,
        ?CarbonInterface $deletedAt = null,
        ?int $scopeLocationId = null
    ): void {
        $vendorTemplateId = trim((string) $template->vendor_template_id);
        if ($vendorTemplateId === '') {
            return;
        }

        $metadata = $this->metadataResolver->fromTemplate($template);
        $payload = [
            'vendor' => $metadata['vendor'],
            'vendor_template_id' => $vendorTemplateId,
            'employee_id' => $employeeId,
            'deleted_at' => ($deletedAt ?? now())->toDateTimeString(),
        ];

        if (Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
            $payload['biometric_type'] = $metadata['biometric_type'];
        }

        if (Schema::hasColumn('employee_template_deletions', 'template_source')) {
            $payload['template_source'] = $metadata['source'];
        }

        if (Schema::hasColumn('employee_template_deletions', 'scope_location_id')) {
            $payload['scope_location_id'] = $scopeLocationId;
        }

        EmployeeTemplateDeletion::query()->create($payload);
    }
}
