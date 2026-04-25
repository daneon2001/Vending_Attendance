<?php

namespace App\Services\Fortia;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogAlignmentService
{
    private const COMPANY_SYNC_COLUMNS = ['fortia_company_id', 'external_id', 'legacy_code'];

    private const LOCATION_SYNC_COLUMNS = ['fortia_location_id', 'external_id', 'legacy_code'];

    private const COMPANY_REFERENCE_COLUMNS = ['fortia_company_id', 'external_id', 'legacy_code', 'code', 'id'];

    private const LOCATION_REFERENCE_COLUMNS = ['fortia_location_id', 'external_id', 'legacy_code', 'code', 'id'];

    /**
     * @return array<string, mixed>
     */
    public function syncOperationalCatalogs(bool $dryRun = false): array
    {
        return [
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'companies' => $this->syncCompaniesFromEmployees($dryRun),
            'locations' => $this->syncLocationsFromEmployees($dryRun),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncCompaniesFromEmployees(bool $dryRun = false): array
    {
        $summary = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_invalid' => 0,
            'skipped_no_safe_external_key' => 0,
            'external_column' => null,
        ];

        if (! Schema::hasTable('employees') || ! Schema::hasTable('companies')) {
            return $summary + ['skipped_reason' => 'missing_tables'];
        }

        $externalColumn = $this->firstExistingColumn('companies', self::COMPANY_SYNC_COLUMNS);
        $summary['external_column'] = $externalColumn;

        if ($externalColumn === null) {
            $summary['skipped_no_safe_external_key'] = (int) DB::table('employees')->whereNotNull('company_id')->count();

            return $summary;
        }

        $rows = DB::table('employees')
            ->selectRaw('company_id, MAX(company_name) as company_name')
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->orderBy('company_id')
            ->get();

        foreach ($rows as $row) {
            $summary['processed']++;

            if (! is_numeric($row->company_id)) {
                $summary['skipped_invalid']++;
                continue;
            }

            $fortiaCompanyId = (int) $row->company_id;
            if ($fortiaCompanyId <= 0) {
                $summary['skipped_invalid']++;
                continue;
            }

            $company = Company::query()->where($externalColumn, $fortiaCompanyId)->first();
            $isNew = false;
            if (! $company) {
                $company = new Company();
                $company->{$externalColumn} = $fortiaCompanyId;
                $isNew = true;
            }

            $companyName = $this->normalizeText($row->company_name);
            if ($companyName !== null) {
                $company->name = $companyName;
            } elseif ($isNew && blank($company->name)) {
                $company->name = 'EMPRESA '.$fortiaCompanyId;
            }

            if (Schema::hasColumn('companies', 'code') && blank($company->code)) {
                $company->code = (string) $fortiaCompanyId;
            }

            if (Schema::hasColumn('companies', 'status') && $company->status === null) {
                $company->status = 1;
            }

            if ($isNew) {
                $summary['inserted']++;
                if (! $dryRun) {
                    $company->save();
                }
                continue;
            }

            if ($company->isDirty()) {
                $summary['updated']++;
                if (! $dryRun) {
                    $company->save();
                }
                continue;
            }

            $summary['unchanged']++;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function syncLocationsFromEmployees(bool $dryRun = false): array
    {
        $summary = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_invalid' => 0,
            'skipped_no_safe_external_key' => 0,
            'unresolved_company' => 0,
            'external_column' => null,
        ];

        if (! Schema::hasTable('employees') || ! Schema::hasTable('locations')) {
            return $summary + ['skipped_reason' => 'missing_tables'];
        }

        $externalColumn = $this->firstExistingColumn('locations', self::LOCATION_SYNC_COLUMNS);
        $summary['external_column'] = $externalColumn;

        if ($externalColumn === null) {
            $summary['skipped_no_safe_external_key'] = (int) DB::table('employees')->whereNotNull('base_location_id')->count();

            return $summary;
        }

        $companyExternalColumn = $this->firstExistingColumn('companies', self::COMPANY_SYNC_COLUMNS);
        $companyMap = [];
        if ($companyExternalColumn !== null && Schema::hasTable('companies')) {
            $companyMap = DB::table('companies')
                ->whereNotNull($companyExternalColumn)
                ->pluck('id', $companyExternalColumn)
                ->mapWithKeys(fn ($id, $fortiaId) => [(string) $fortiaId => (int) $id])
                ->all();
        }

        $rows = DB::table('employees')
            ->selectRaw('base_location_id, MAX(base_location_name) as base_location_name, MAX(company_id) as company_id')
            ->whereNotNull('base_location_id')
            ->groupBy('base_location_id')
            ->orderBy('base_location_id')
            ->get();

        foreach ($rows as $row) {
            $summary['processed']++;

            if (! is_numeric($row->base_location_id)) {
                $summary['skipped_invalid']++;
                continue;
            }

            $fortiaLocationId = (int) $row->base_location_id;
            if ($fortiaLocationId <= 0) {
                $summary['skipped_invalid']++;
                continue;
            }

            $location = Location::query()->where($externalColumn, $fortiaLocationId)->first();
            $isNew = false;
            if (! $location) {
                $location = new Location();
                $location->{$externalColumn} = $fortiaLocationId;
                $isNew = true;
            }

            $locationName = $this->normalizeText($row->base_location_name);
            if ($locationName !== null) {
                $location->name = $locationName;
            } elseif ($isNew && blank($location->name)) {
                $location->name = 'UBICACION '.$fortiaLocationId;
            }

            $rawCompanyId = $this->normalizeKey($row->company_id);
            if ($rawCompanyId !== null && isset($companyMap[$rawCompanyId])) {
                $location->company_id = $companyMap[$rawCompanyId];
            } elseif ($rawCompanyId !== null) {
                $summary['unresolved_company']++;
            }

            if (Schema::hasColumn('locations', 'code') && blank($location->code)) {
                $location->code = (string) $fortiaLocationId;
            }

            if (Schema::hasColumn('locations', 'status') && $location->status === null) {
                $location->status = 1;
            }

            if ($isNew) {
                $summary['inserted']++;
                if (! $dryRun) {
                    $location->save();
                }
                continue;
            }

            if ($location->isDirty()) {
                $summary['updated']++;
                if (! $dryRun) {
                    $location->save();
                }
                continue;
            }

            $summary['unchanged']++;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditAlignment(int $sample = 5): array
    {
        $sample = max(1, min($sample, 20));

        $companyReferenceColumn = $this->firstExistingColumn('companies', self::COMPANY_REFERENCE_COLUMNS);
        $locationReferenceColumn = $this->firstExistingColumn('locations', self::LOCATION_REFERENCE_COLUMNS);

        $companyCatalog = $this->loadCatalogMap(
            table: 'companies',
            keyColumn: $companyReferenceColumn,
            nameColumn: 'name'
        );

        $locationCatalog = $this->loadCatalogMap(
            table: 'locations',
            keyColumn: $locationReferenceColumn,
            nameColumn: 'name'
        );

        $result = [
            'references' => [
                'company_column_for_employee_match' => $companyReferenceColumn,
                'location_column_for_employee_match' => $locationReferenceColumn,
                'legacy_table_empleados_exists' => Schema::hasTable('empleados'),
            ],
            'counts' => [
                'employees_total' => Schema::hasTable('employees') ? (int) DB::table('employees')->count() : 0,
                'companies_total' => Schema::hasTable('companies') ? (int) DB::table('companies')->count() : 0,
                'locations_total' => Schema::hasTable('locations') ? (int) DB::table('locations')->count() : 0,
                'employee_details_total' => Schema::hasTable('employee_details') ? (int) DB::table('employee_details')->count() : 0,
                'employees_without_company_match' => 0,
                'employees_without_location_match' => 0,
                'employees_without_location_match_by_fortia_location_id' => 0,
                'employees_wrongly_matching_internal_location_id' => 0,
                'employees_with_company_name_mismatch' => 0,
                'employees_with_location_name_mismatch' => 0,
                'ubicaciones_without_operational_location' => 0,
                'razones_sociales_without_operational_company' => 0,
                'employees_without_employee_details' => 0,
                'employee_details_with_company_code_mismatch' => 0,
                'employee_details_with_location_code_mismatch' => 0,
                'employee_details_with_department_code_mismatch' => 0,
            ],
            'samples' => [
                'employees_without_company_match' => [],
                'employees_without_location_match' => [],
                'employees_with_company_name_mismatch' => [],
                'employees_with_location_name_mismatch' => [],
                'employees_wrongly_matching_internal_location_id' => [],
            ],
        ];

        if (
            Schema::hasTable('employees')
            && Schema::hasTable('locations')
            && Schema::hasColumn('locations', 'fortia_location_id')
        ) {
            $result['counts']['employees_without_location_match_by_fortia_location_id'] = (int) DB::table('employees as e')
                ->leftJoin('locations as l', 'l.fortia_location_id', '=', 'e.base_location_id')
                ->whereNotNull('e.base_location_id')
                ->whereNull('l.id')
                ->count();

            $result['counts']['employees_wrongly_matching_internal_location_id'] = (int) DB::table('employees as e')
                ->join('locations as l', 'l.id', '=', 'e.base_location_id')
                ->whereNotNull('l.fortia_location_id')
                ->whereRaw('CAST(l.fortia_location_id AS CHAR) <> CAST(e.base_location_id AS CHAR)')
                ->count();

            $misalignedByInternal = DB::table('employees as e')
                ->join('locations as l', 'l.id', '=', 'e.base_location_id')
                ->whereNotNull('l.fortia_location_id')
                ->whereRaw('CAST(l.fortia_location_id AS CHAR) <> CAST(e.base_location_id AS CHAR)')
                ->select([
                    'e.id as employee_id',
                    'e.fortia_employee_id',
                    'e.base_location_id',
                    'e.base_location_name',
                    'l.id as matched_internal_location_id',
                    'l.fortia_location_id as matched_internal_location_fortia_id',
                    'l.name as matched_internal_location_name',
                ])
                ->orderBy('e.id')
                ->limit($sample)
                ->get();

            foreach ($misalignedByInternal as $row) {
                $this->appendSample($result['samples']['employees_wrongly_matching_internal_location_id'], [
                    'employee_id' => $row->employee_id,
                    'fortia_employee_id' => $row->fortia_employee_id,
                    'base_location_id' => $row->base_location_id,
                    'base_location_name' => $row->base_location_name,
                    'matched_internal_location_id' => $row->matched_internal_location_id,
                    'matched_internal_location_fortia_id' => $row->matched_internal_location_fortia_id,
                    'matched_internal_location_name' => $row->matched_internal_location_name,
                ], $sample);
            }
        }

        if (Schema::hasTable('employees')) {
            DB::table('employees')
                ->select('id', 'fortia_employee_id', 'company_id', 'company_name', 'base_location_id', 'base_location_name')
                ->orderBy('id')
                ->cursor()
                ->each(function ($employee) use (&$result, $companyCatalog, $locationCatalog, $sample): void {
                    $companyKey = $this->normalizeKey($employee->company_id);
                    $locationKey = $this->normalizeKey($employee->base_location_id);
                    $companyName = $this->normalizeText($employee->company_name);
                    $locationName = $this->normalizeText($employee->base_location_name);

                    if ($companyKey !== null) {
                        $catalogCompany = $companyCatalog[$companyKey] ?? null;
                        if (! $catalogCompany) {
                            $result['counts']['employees_without_company_match']++;
                            $this->appendSample($result['samples']['employees_without_company_match'], [
                                'employee_id' => $employee->id,
                                'fortia_employee_id' => $employee->fortia_employee_id,
                                'company_id' => $employee->company_id,
                                'company_name' => $employee->company_name,
                            ], $sample);
                        } elseif ($companyName !== null && $this->normalizeText($catalogCompany['name']) !== $companyName) {
                            $result['counts']['employees_with_company_name_mismatch']++;
                            $this->appendSample($result['samples']['employees_with_company_name_mismatch'], [
                                'employee_id' => $employee->id,
                                'fortia_employee_id' => $employee->fortia_employee_id,
                                'company_id' => $employee->company_id,
                                'employee_company_name' => $employee->company_name,
                                'catalog_company_name' => $catalogCompany['name'],
                            ], $sample);
                        }
                    }

                    if ($locationKey !== null) {
                        $catalogLocation = $locationCatalog[$locationKey] ?? null;
                        if (! $catalogLocation) {
                            $result['counts']['employees_without_location_match']++;
                            $this->appendSample($result['samples']['employees_without_location_match'], [
                                'employee_id' => $employee->id,
                                'fortia_employee_id' => $employee->fortia_employee_id,
                                'base_location_id' => $employee->base_location_id,
                                'base_location_name' => $employee->base_location_name,
                            ], $sample);
                        } elseif ($locationName !== null && $this->normalizeText($catalogLocation['name']) !== $locationName) {
                            $result['counts']['employees_with_location_name_mismatch']++;
                            $this->appendSample($result['samples']['employees_with_location_name_mismatch'], [
                                'employee_id' => $employee->id,
                                'fortia_employee_id' => $employee->fortia_employee_id,
                                'base_location_id' => $employee->base_location_id,
                                'employee_location_name' => $employee->base_location_name,
                                'catalog_location_name' => $catalogLocation['name'],
                            ], $sample);
                        }
                    }
                });
        }

        if (Schema::hasTable('ubicaciones')) {
            $result['counts']['ubicaciones_without_operational_location'] = (int) DB::table('ubicaciones')
                ->select('cla_ubicacion')
                ->cursor()
                ->filter(function ($row) use ($locationCatalog): bool {
                    $key = $this->normalizeKey($row->cla_ubicacion);

                    return $key !== null && ! isset($locationCatalog[$key]);
                })
                ->count();
        }

        if (Schema::hasTable('razones_sociales')) {
            $result['counts']['razones_sociales_without_operational_company'] = (int) DB::table('razones_sociales')
                ->select('cla_razon_social')
                ->cursor()
                ->filter(function ($row) use ($companyCatalog): bool {
                    $key = $this->normalizeKey($row->cla_razon_social);

                    return $key !== null && ! isset($companyCatalog[$key]);
                })
                ->count();
        }

        if (Schema::hasTable('employees') && Schema::hasTable('employee_details')) {
            $result['counts']['employees_without_employee_details'] = (int) DB::table('employees as e')
                ->leftJoin('employee_details as d', 'd.employee_id', '=', 'e.id')
                ->whereNull('d.id')
                ->count();
        }

        if (Schema::hasTable('employee_details') && Schema::hasTable('employees')) {
            DB::table('employee_details as d')
                ->join('employees as e', 'e.id', '=', 'd.employee_id')
                ->leftJoin('razones_sociales as rs', 'rs.id', '=', 'd.razon_social_id')
                ->leftJoin('ubicaciones as ub', 'ub.id', '=', 'd.ubicacion_id')
                ->leftJoin('departamentos as dep', 'dep.id', '=', 'd.departamento_id')
                ->select([
                    'e.company_id',
                    'e.base_location_id',
                    'e.department_id',
                    'rs.cla_razon_social',
                    'ub.cla_ubicacion',
                    'dep.cla_depto',
                ])
                ->cursor()
                ->each(function ($row) use (&$result): void {
                    $employeeCompany = $this->normalizeKey($row->company_id);
                    $employeeLocation = $this->normalizeKey($row->base_location_id);
                    $employeeDepartment = $this->normalizeKey($row->department_id);

                    $detailCompany = $this->normalizeKey($row->cla_razon_social);
                    $detailLocation = $this->normalizeKey($row->cla_ubicacion);
                    $detailDepartment = $this->normalizeKey($row->cla_depto);

                    if ($employeeCompany !== null && $detailCompany !== null && $employeeCompany !== $detailCompany) {
                        $result['counts']['employee_details_with_company_code_mismatch']++;
                    }

                    if ($employeeLocation !== null && $detailLocation !== null && $employeeLocation !== $detailLocation) {
                        $result['counts']['employee_details_with_location_code_mismatch']++;
                    }

                    if ($employeeDepartment !== null && $detailDepartment !== null && $employeeDepartment !== $detailDepartment) {
                        $result['counts']['employee_details_with_department_code_mismatch']++;
                    }
                });
        }

        return $result;
    }

    /**
     * @return array<string, array{id: int, name: string|null}>
     */
    private function loadCatalogMap(string $table, ?string $keyColumn, string $nameColumn): array
    {
        if ($keyColumn === null || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->whereNotNull($keyColumn)
            ->select('id', $nameColumn, $keyColumn)
            ->get()
            ->reduce(function (array $carry, $row) use ($keyColumn, $nameColumn): array {
                $key = $this->normalizeKey($row->{$keyColumn});
                if ($key === null) {
                    return $carry;
                }

                $carry[$key] = [
                    'id' => (int) $row->id,
                    'name' => $this->normalizeText($row->{$nameColumn}),
                ];

                return $carry;
            }, []);
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function normalizeKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sampleBucket
     * @param  array<string, mixed>  $sample
     */
    private function appendSample(array &$sampleBucket, array $sample, int $limit): void
    {
        if (count($sampleBucket) >= $limit) {
            return;
        }

        $sampleBucket[] = $sample;
    }
}
