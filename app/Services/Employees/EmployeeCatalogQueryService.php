<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EmployeeCatalogQueryService
{
    /**
     * Shared employee catalog query reused by list and future XLSX export.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $select
     * @param  list<string>  $with
     */
    public function buildFilteredEmployeeQuery(array $filters, array $select = ['*'], array $with = []): Builder
    {
        $query = Employee::query();

        if ($select !== ['*']) {
            $query->select($select);
        }

        if ($with !== []) {
            $query->with($with);
        }

        $this->applyEmployeeSearch($query, isset($filters['q']) ? (string) $filters['q'] : null);
        $this->applyLocationFilter($query, $filters['location_id'] ?? $filters['unit_id'] ?? null);
        $this->applyCompanyFilters($query, $filters);
        $this->applyStatusFilter($query, $filters['status'] ?? null);
        $this->applyFingerprintFilter($query, $filters['fingerprint'] ?? null);
        $this->applyFaceFilter($query, $filters['face'] ?? null);
        $this->applySyncReadyFilter($query, $filters['sync_ready'] ?? null);

        return $query;
    }

    public function applySearchFilter(Builder $query, ?string $search): void
    {
        $this->applyEmployeeSearch($query, $search);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Location>
     */
    public function listLocations(): Collection
    {
        return Location::query()
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();
    }

    private function applyCompanyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['company_name'])) {
            $query->where('company_name', 'like', '%'.trim((string) $filters['company_name']).'%');
        }
    }

    private function applyStatusFilter(Builder $query, mixed $status): void
    {
        if (empty($status)) {
            return;
        }

        $query->where('status', $this->normalizeStatusFilter((string) $status));
    }

    private function applyFingerprintFilter(Builder $query, mixed $fingerprint): void
    {
        if (empty($fingerprint)) {
            return;
        }

        $query->where('has_fingerprint', $fingerprint === 'with');
    }

    private function applyFaceFilter(Builder $query, mixed $face): void
    {
        if (empty($face) || ! Schema::hasColumn('employees', 'has_face_enrollment')) {
            return;
        }

        $query->where('has_face_enrollment', $face === 'with');
    }

    private function applySyncReadyFilter(Builder $query, mixed $syncReady): void
    {
        $normalized = filter_var($syncReady, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($normalized !== true || ! Schema::hasColumn('employees', 'has_face_enrollment')) {
            return;
        }

        $query->faceSyncReady();
    }

    private function applyLocationFilter(Builder $query, mixed $locationFilter): void
    {
        if ($locationFilter === null || $locationFilter === '') {
            return;
        }

        if ((string) $locationFilter === 'unassigned') {
            $this->applyUnassignedLocationFilter($query);

            return;
        }

        $location = $this->resolveLocation($locationFilter);

        if (! $location) {
            $query->whereRaw('1 = 0');

            return;
        }

        $baseLocationCandidates = $this->resolveEmployeeBaseLocationCandidates($location);

        if ($baseLocationCandidates === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        // In employee catalog, "Unidad" means assigned/base unit only.
        $query->whereIn('base_location_id', $baseLocationCandidates);
    }

    private function applyUnassignedLocationFilter(Builder $query): void
    {
        $query->whereNull('base_location_id');
    }

    private function applyEmployeeSearch(Builder $query, ?string $search): void
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $search));
        if (! is_string($normalized) || $normalized === '') {
            return;
        }

        $normalized = mb_substr($normalized, 0, 120);
        $tokens = collect(explode(' ', $normalized))
            ->map(static fn ($token) => trim($token))
            ->filter()
            ->take(8)
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $searchableColumns = $this->employeeSearchableColumns();
        if ($searchableColumns === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($tokens, $searchableColumns): void {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $tokenQuery) use ($token, $searchableColumns): void {
                    $isNumericToken = preg_match('/^\d+$/', $token) === 1;

                    foreach ($searchableColumns as $column) {
                        if ($isNumericToken && in_array($column, ['id', 'fortia_employee_id', 'employee_code'], true)) {
                            $tokenQuery->orWhere($column, $token)
                                ->orWhere($column, 'like', '%'.$token.'%');
                        } else {
                            $tokenQuery->orWhere($column, 'like', '%'.$token.'%');
                        }
                    }
                });
            }
        });
    }

    /**
     * @return list<string>
     */
    private function employeeSearchableColumns(): array
    {
        $columns = [
            'id',
            'full_name',
            'name',
            'last_name',
            'second_last_name',
            'employee_code',
            'fortia_employee_id',
            'rfc',
            'curp',
            'company_name',
            'base_location_name',
        ];

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('employees', $column)
        ));
    }

    /**
     * @return list<int>
     */
    private function resolveEmployeeBaseLocationCandidates(Location $location): array
    {
        return collect([
            $location->id,
            is_numeric($location->fortia_location_id ?? null) ? (int) $location->fortia_location_id : null,
            is_numeric($location->code ?? null) ? (int) $location->code : null,
        ])
            ->filter(static fn ($value): bool => is_int($value) && $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveLocation(mixed $locationFilter): ?Location
    {
        if (! is_numeric((string) $locationFilter) || (int) $locationFilter <= 0) {
            return null;
        }

        $locationId = (int) $locationFilter;

        return Location::query()
            ->select('id', 'fortia_location_id', 'code', 'name')
            ->where(function (Builder $locationQuery) use ($locationId): void {
                $locationQuery->whereKey($locationId);

                if (Schema::hasColumn('locations', 'fortia_location_id')) {
                    $locationQuery->orWhere('fortia_location_id', $locationId);
                }

                if (Schema::hasColumn('locations', 'code')) {
                    $locationQuery->orWhere('code', (string) $locationId);
                }
            })
            ->first();
    }

    private function normalizeStatusFilter(string $status): string
    {
        return in_array(strtolower($status), ['active', 'a'], true) ? 'A' : 'B';
    }
}
