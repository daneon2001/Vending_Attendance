<?php

namespace App\Services\Biometrics;

use App\Models\Employee;
use App\Models\EmployeeScopeDeletion;
use App\Models\Location;
use Illuminate\Support\Facades\Schema;

class EmployeeScopeSyncService
{
    public function __construct(private readonly TemplateDeletionService $templateDeletionService)
    {
    }

    public function recordScopeLosses(Employee $employee): void
    {
        if (! $employee->exists || ! Schema::hasTable('employee_scope_deletions')) {
            return;
        }

        if (! $this->relevantScopeFieldsChanged($employee)) {
            return;
        }

        $before = $this->resolveAccessibleLocationIds(
            $this->integerOrNull($employee->getOriginal('base_location_id')),
            $this->booleanOrFalse($employee->getOriginal('can_check_all_branches')),
            (string) $employee->getOriginal('status')
        );
        $after = $this->resolveAccessibleLocationIds(
            $this->integerOrNull($employee->base_location_id),
            (bool) ($employee->can_check_all_branches ?? false),
            (string) $employee->status
        );

        $lostLocationIds = array_values(array_diff($before, $after));
        if ($lostLocationIds === []) {
            return;
        }

        $deletedAt = now();

        foreach ($lostLocationIds as $locationId) {
            EmployeeScopeDeletion::query()->create([
                'employee_id' => (int) $employee->id,
                'scope_location_id' => $locationId,
                'deleted_at' => $deletedAt,
                'reason' => 'scope_lost',
            ]);
        }

        if (! Schema::hasTable('employee_template_deletions')) {
            return;
        }

        $templateColumns = ['id', 'employee_id', 'vendor_template_id', 'enrolment_type'];
        if (Schema::hasColumn('employee_fingerprints', 'template_vendor')) {
            $templateColumns[] = 'template_vendor';
        }
        if (Schema::hasColumn('employee_fingerprints', 'template_source')) {
            $templateColumns[] = 'template_source';
        }

        $templates = $employee->fingerprints()
            ->active()
            ->whereNull('deleted_at')
            ->whereNotNull('vendor_template_id')
            ->where('vendor_template_id', '<>', '')
            ->get($templateColumns);

        foreach ($lostLocationIds as $locationId) {
            foreach ($templates as $template) {
                $this->templateDeletionService->recordForTemplate(
                    template: $template,
                    employeeId: (int) $employee->id,
                    deletedAt: $deletedAt,
                    scopeLocationId: $locationId,
                );
            }
        }
    }

    private function relevantScopeFieldsChanged(Employee $employee): bool
    {
        if ($employee->wasChanged('status') || $employee->wasChanged('base_location_id')) {
            return true;
        }

        return Schema::hasColumn('employees', 'can_check_all_branches') && $employee->wasChanged('can_check_all_branches');
    }

    /**
     * @return array<int, int>
     */
    private function resolveAccessibleLocationIds(?int $baseLocationId, bool $canCheckAllBranches, string $status): array
    {
        if (! $this->isActiveStatus($status)) {
            return [];
        }

        if (! $canCheckAllBranches) {
            return $baseLocationId !== null ? [$baseLocationId] : [];
        }

        if (! Schema::hasTable('locations')) {
            return $baseLocationId !== null ? [$baseLocationId] : [];
        }

        $locationIds = Location::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($baseLocationId !== null && ! in_array($baseLocationId, $locationIds, true)) {
            $locationIds[] = $baseLocationId;
        }

        sort($locationIds);

        return array_values(array_unique($locationIds));
    }

    private function isActiveStatus(string $status): bool
    {
        return in_array(strtoupper(trim($status)), ['A', 'ACTIVE'], true);
    }

    private function booleanOrFalse(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
