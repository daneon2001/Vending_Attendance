<?php

namespace App\Services\Employees;

use App\Models\Area;
use App\Models\CentroCosto;
use App\Models\Company;
use App\Models\Departamento;
use App\Models\Location;
use App\Models\PeriodoPago;
use App\Models\Puesto;
use App\Models\RazonSocial;
use App\Models\RegistroImss;
use App\Models\Ubicacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EmployeeExcelCatalogResolutionService
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $resolveCache = [];

    /**
     * @return array<string, mixed>
     */
    public function resolveRowDependencies(array $values): array
    {
        $companyDependency = $this->resolveOperationalCompany(
            $values['cla_razon_social'] ?? null,
            $values['nom_razon_social'] ?? null
        );

        $dependencies = [
            'companies' => $companyDependency,
            'locations' => $this->resolveOperationalLocation(
                $values['cla_ubicacion'] ?? null,
                $values['nom_ubicacion'] ?? null,
                $companyDependency
            ),
            'razones_sociales' => $this->resolveCatalogRecord(
                type: 'razones_sociales',
                label: 'Razones sociales',
                table: 'razones_sociales',
                modelClass: RazonSocial::class,
                codeColumn: 'cla_razon_social',
                nameColumn: 'nom_razon_social',
                code: $values['cla_razon_social'] ?? null,
                name: $values['nom_razon_social'] ?? null
            ),
            'registros_imss' => $this->resolveCatalogRecord(
                type: 'registros_imss',
                label: 'Registros IMSS',
                table: 'registros_imss',
                modelClass: RegistroImss::class,
                codeColumn: 'cla_reg_imss',
                nameColumn: 'nom_reg_imss',
                code: $values['cla_reg_imss'] ?? null,
                name: $values['nom_reg_imss'] ?? null
            ),
            'puestos' => $this->resolveCatalogRecord(
                type: 'puestos',
                label: 'Puestos',
                table: 'puestos',
                modelClass: Puesto::class,
                codeColumn: 'cla_puesto',
                nameColumn: 'nom_puesto',
                code: $values['cla_puesto'] ?? null,
                name: $values['nom_puesto'] ?? null
            ),
            'centros_costo' => $this->resolveCatalogRecord(
                type: 'centros_costo',
                label: 'Centros de costo',
                table: 'centros_costo',
                modelClass: CentroCosto::class,
                codeColumn: 'cla_centro_costo',
                nameColumn: 'nom_centro_costo',
                code: $values['cla_centro_costo'] ?? null,
                name: $values['nom_centro_costo'] ?? null
            ),
            'areas' => $this->resolveCatalogRecord(
                type: 'areas',
                label: 'Areas',
                table: 'areas',
                modelClass: Area::class,
                codeColumn: 'cla_area',
                nameColumn: 'nom_area',
                code: $values['cla_area'] ?? null,
                name: $values['nom_area'] ?? null
            ),
            'departamentos' => $this->resolveCatalogRecord(
                type: 'departamentos',
                label: 'Departamentos',
                table: 'departamentos',
                modelClass: Departamento::class,
                codeColumn: 'cla_depto',
                nameColumn: 'nom_departamento',
                code: $values['cla_depto'] ?? null,
                name: $values['nom_departamento'] ?? null
            ),
            'ubicaciones_laborales' => $this->resolveCatalogRecord(
                type: 'ubicaciones_laborales',
                label: 'Ubicaciones laborales',
                table: 'ubicaciones',
                modelClass: Ubicacion::class,
                codeColumn: 'cla_ubicacion',
                nameColumn: 'nom_ubicacion',
                code: $values['cla_ubicacion'] ?? null,
                name: $values['nom_ubicacion'] ?? null
            ),
            'periodos_pago' => $this->resolveCatalogRecord(
                type: 'periodos_pago',
                label: 'Periodos de pago',
                table: 'periodos_pago',
                modelClass: PeriodoPago::class,
                codeColumn: 'cla_periodo_pago',
                nameColumn: 'nom_periodo_pago',
                code: $values['cla_periodo_pago'] ?? null,
                name: $values['nom_periodo_pago'] ?? null
            ),
        ];

        $missingDependencies = collect($dependencies)
            ->filter(fn (array $dependency) => ($dependency['status'] ?? null) === 'missing')
            ->map(fn (array $dependency) => $this->formatMissingDependency($dependency))
            ->values()
            ->all();

        $warnings = collect($missingDependencies)
            ->map(function (array $dependency): string {
                if (($dependency['can_create'] ?? false) === true) {
                    return $dependency['label'].' pendiente de alta.';
                }

                return $dependency['label'].': '.$dependency['create_reason'];
            })
            ->values()
            ->all();

        $blocking = $missingDependencies !== [];
        $resolvable = $blocking
            && collect($missingDependencies)->every(fn (array $dependency) => ($dependency['can_create'] ?? false) === true);

        return [
            'dependencies' => $dependencies,
            'missing_dependencies' => $missingDependencies,
            'blocking' => $blocking,
            'resolvable' => $resolvable,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $preparedRows
     * @return array<string, mixed>
     */
    public function buildCatalogSummary(array $preparedRows): array
    {
        $rowsWithMissingCatalogs = collect($preparedRows)
            ->filter(fn (array $row) => ($row['blocked_by_catalogs'] ?? false) === true)
            ->values();

        $items = [];

        foreach ($rowsWithMissingCatalogs as $row) {
            foreach ($row['missing_catalogs'] ?? [] as $dependency) {
                $identity = $this->buildMissingItemIdentity($dependency);

                if (! isset($items[$identity])) {
                    $items[$identity] = [
                        'type' => $dependency['type'],
                        'label' => $dependency['label'],
                        'code' => $dependency['code'],
                        'name' => $dependency['name'],
                        'employees_affected' => 0,
                        'row_numbers' => [],
                        'can_create' => (bool) ($dependency['can_create'] ?? false),
                        'create_reason' => $dependency['create_reason'] ?? null,
                        'action_suggested' => $dependency['action_suggested'] ?? 'manual_resolution',
                        'related_company_code' => $dependency['related_company_code'] ?? null,
                        'related_company_name' => $dependency['related_company_name'] ?? null,
                    ];
                }

                $items[$identity]['employees_affected']++;
                $items[$identity]['row_numbers'][] = (int) $row['row_number'];
            }
        }

        $itemCollection = collect($items)
            ->map(function (array $item): array {
                $item['row_numbers'] = collect($item['row_numbers'])
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $item['employees_affected'] = count($item['row_numbers']);

                return $item;
            })
            ->sortBy([
                ['type', 'asc'],
                ['code', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        $byType = [];
        foreach ($itemCollection as $item) {
            $type = $item['type'];

            if (! isset($byType[$type])) {
                $byType[$type] = [
                    'type' => $type,
                    'label' => $item['label'],
                    'missing_count' => 0,
                    'affected_rows' => 0,
                    'creatable_count' => 0,
                    'blocking_count' => 0,
                ];
            }

            $byType[$type]['missing_count']++;
            $byType[$type]['affected_rows'] += (int) $item['employees_affected'];
            if ($item['can_create']) {
                $byType[$type]['creatable_count']++;
            } else {
                $byType[$type]['blocking_count']++;
            }
        }

        $employeesResolvables = $rowsWithMissingCatalogs
            ->filter(fn (array $row) => ($row['can_be_resolved_by_catalog_creation'] ?? false) === true)
            ->count();

        return [
            'missing_total' => $itemCollection->count(),
            'missing_types' => count($byType),
            'creatable_total' => $itemCollection->where('can_create', true)->count(),
            'blocking_total' => $itemCollection->where('can_create', false)->count(),
            'employees_pending' => $rowsWithMissingCatalogs->count(),
            'employees_resolvable_if_created' => $employeesResolvables,
            'by_type' => array_values($byType),
            'items' => $itemCollection->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $preparedRows
     * @return array<string, mixed>
     */
    public function createMissingCatalogs(array $preparedRows): array
    {
        $summary = $this->buildCatalogSummary($preparedRows);
        $itemsByType = collect($summary['items'] ?? [])
            ->filter(fn (array $item) => ($item['can_create'] ?? false) === true)
            ->groupBy('type');

        $createdSummary = [
            'companies' => 0,
            'locations' => 0,
            'razones_sociales' => 0,
            'registros_imss' => 0,
            'puestos' => 0,
            'centros_costo' => 0,
            'areas' => 0,
            'departamentos' => 0,
            'ubicaciones_laborales' => 0,
            'periodos_pago' => 0,
        ];

        if ($itemsByType->isEmpty()) {
            return [
                'summary' => $createdSummary,
                'created_total' => 0,
            ];
        }

        DB::transaction(function () use ($itemsByType, &$createdSummary): void {
            foreach ([
                'companies',
                'razones_sociales',
                'registros_imss',
                'puestos',
                'centros_costo',
                'areas',
                'departamentos',
                'ubicaciones_laborales',
                'periodos_pago',
                'locations',
            ] as $type) {
                /** @var Collection<int, array<string, mixed>> $items */
                $items = $itemsByType->get($type, collect());

                foreach ($items as $item) {
                    if ($this->ensureCatalogItemExists($type, $item)) {
                        $createdSummary[$type]++;
                    }
                }
            }
        });

        return [
            'summary' => $createdSummary,
            'created_total' => array_sum($createdSummary),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveOperationalCompany(?string $code, ?string $name): array
    {
        $code = $this->cleanText($code);
        $name = $this->cleanText($name);
        $cacheKey = $this->buildCacheKey('companies', $code, $name);

        if (isset($this->resolveCache[$cacheKey])) {
            return $this->resolveCache[$cacheKey];
        }

        $default = [
            'type' => 'companies',
            'label' => 'Empresas',
            'status' => 'not_provided',
            'critical' => true,
            'code' => $code,
            'name' => $name,
            'stored_value' => null,
            'catalog_id' => null,
            'resolved_name' => $name,
            'can_create' => false,
            'create_reason' => null,
        ];

        if ($code === null && $name === null) {
            return $this->resolveCache[$cacheKey] = $default;
        }

        if (! Schema::hasTable('companies')) {
            return $this->resolveCache[$cacheKey] = array_merge($default, [
                'status' => 'missing',
                'create_reason' => 'Catalogo operativo de empresas no disponible.',
            ]);
        }

        $query = Company::query();
        $company = null;

        if ($code !== null) {
            if (Schema::hasColumn('companies', 'fortia_company_id') && preg_match('/^\d+$/', $code) === 1) {
                $company = (clone $query)->where('fortia_company_id', (int) $code)->first();
            }

            if ($company === null && Schema::hasColumn('companies', 'code')) {
                $company = (clone $query)
                    ->whereRaw('UPPER(TRIM(code)) = ?', [mb_strtoupper($code)])
                    ->first();
            }
        }

        if ($company === null && $name !== null) {
            $company = (clone $query)
                ->whereRaw('UPPER(TRIM(name)) = ?', [mb_strtoupper($name)])
                ->first();

            if (! $company instanceof Company) {
                $company = (clone $query)
                    ->get()
                    ->first(fn (Company $candidate) => $this->normalizeComparisonValue($candidate->name) === $this->normalizeComparisonValue($name));
            }
        }

        if ($company instanceof Company) {
            return $this->resolveCache[$cacheKey] = array_merge($default, [
                'status' => 'resolved',
                'stored_value' => $this->resolveForeignStoredValue('companies', $company),
                'catalog_id' => $company->id,
                'resolved_name' => $company->name,
            ]);
        }

        return $this->resolveCache[$cacheKey] = array_merge($default, [
            'status' => 'missing',
            'can_create' => $code !== null,
            'create_reason' => $code !== null
                ? null
                : 'Se requiere CLA_RAZON_SOCIAL para crear la empresa operativa.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $companyDependency
     * @return array<string, mixed>
     */
    private function resolveOperationalLocation(?string $code, ?string $name, array $companyDependency): array
    {
        $code = $this->cleanText($code);
        $name = $this->cleanText($name);
        $relatedCompanyCode = $companyDependency['code'] ?? null;
        $relatedCompanyName = $companyDependency['resolved_name'] ?? $companyDependency['name'] ?? null;
        $relatedCompanyId = $companyDependency['catalog_id'] ?? null;
        $cacheKey = $this->buildCacheKey('locations', $code, $name, (string) $relatedCompanyId, $relatedCompanyCode, $relatedCompanyName);

        if (isset($this->resolveCache[$cacheKey])) {
            return $this->resolveCache[$cacheKey];
        }

        $default = [
            'type' => 'locations',
            'label' => 'Ubicaciones',
            'status' => 'not_provided',
            'critical' => true,
            'code' => $code,
            'name' => $name,
            'stored_value' => null,
            'catalog_id' => null,
            'resolved_name' => $name,
            'can_create' => false,
            'create_reason' => null,
            'related_company_code' => $relatedCompanyCode,
            'related_company_name' => $relatedCompanyName,
            'related_company_id' => $relatedCompanyId,
        ];

        if ($code === null && $name === null) {
            return $this->resolveCache[$cacheKey] = $default;
        }

        if (! Schema::hasTable('locations')) {
            return $this->resolveCache[$cacheKey] = array_merge($default, [
                'status' => 'missing',
                'create_reason' => 'Catalogo operativo de ubicaciones no disponible.',
            ]);
        }

        $query = Location::query();
        $location = null;

        if ($code !== null && Schema::hasColumn('locations', 'fortia_location_id') && preg_match('/^\d+$/', $code) === 1) {
            $location = (clone $query)->where('fortia_location_id', (int) $code)->first();
        }

        if ($location === null && $code !== null && Schema::hasColumn('locations', 'code')) {
            $codeQuery = (clone $query)->whereRaw('UPPER(TRIM(code)) = ?', [mb_strtoupper($code)]);
            if ($relatedCompanyId !== null && Schema::hasColumn('locations', 'company_id')) {
                $codeQuery->where('company_id', (int) $relatedCompanyId);
            }
            $location = $codeQuery->first();
        }

        if ($location === null && $name !== null) {
            $nameQuery = (clone $query)->whereRaw('UPPER(TRIM(name)) = ?', [mb_strtoupper($name)]);
            if ($relatedCompanyId !== null && Schema::hasColumn('locations', 'company_id')) {
                $nameQuery->where('company_id', (int) $relatedCompanyId);
            }
            $location = $nameQuery->first();

            if (! $location instanceof Location) {
                $location = (clone $query)
                    ->get()
                    ->first(function (Location $candidate) use ($name, $relatedCompanyId): bool {
                        if ($relatedCompanyId !== null && (int) ($candidate->company_id ?? 0) !== (int) $relatedCompanyId) {
                            return false;
                        }

                        return $this->normalizeComparisonValue($candidate->name) === $this->normalizeComparisonValue($name);
                    });
            }
        }

        if ($location instanceof Location) {
            return $this->resolveCache[$cacheKey] = array_merge($default, [
                'status' => 'resolved',
                'stored_value' => $this->resolveForeignStoredValue('locations', $location),
                'catalog_id' => $location->id,
                'resolved_name' => $location->name,
            ]);
        }

        return $this->resolveCache[$cacheKey] = array_merge($default, [
            'status' => 'missing',
            'can_create' => $code !== null,
            'create_reason' => $code !== null
                ? null
                : 'Se requiere CLA_UBICACION para crear la ubicacion operativa.',
        ]);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, mixed>
     */
    private function resolveCatalogRecord(
        string $type,
        string $label,
        string $table,
        string $modelClass,
        string $codeColumn,
        string $nameColumn,
        ?string $code,
        ?string $name
    ): array {
        $code = $this->cleanText($code);
        $name = $this->cleanText($name);
        $cacheKey = $this->buildCacheKey($type, $code, $name);

        if (isset($this->resolveCache[$cacheKey])) {
            return $this->resolveCache[$cacheKey];
        }

        $default = [
            'type' => $type,
            'label' => $label,
            'status' => 'not_provided',
            'critical' => true,
            'code' => $code,
            'name' => $name,
            'stored_value' => null,
            'catalog_id' => null,
            'resolved_name' => $name,
            'can_create' => false,
            'create_reason' => null,
            'table' => $table,
            'code_column' => $codeColumn,
            'name_column' => $nameColumn,
            'model_class' => $modelClass,
        ];

        if ($code === null && $name === null) {
            return $this->resolveCache[$cacheKey] = $default;
        }

        if (! Schema::hasTable($table)) {
            return $this->resolveCache[$cacheKey] = array_merge($default, [
                'status' => 'missing',
                'create_reason' => "La tabla {$table} no existe.",
            ]);
        }

        /** @var Model|null $record */
        $record = null;
        if ($code !== null) {
            $record = $modelClass::query()
                ->whereRaw("UPPER(TRIM({$codeColumn})) = ?", [mb_strtoupper($code)])
                ->first();
        }

        if (! $record instanceof Model && $name !== null) {
            $record = $modelClass::query()
                ->whereRaw("UPPER(TRIM({$nameColumn})) = ?", [mb_strtoupper($name)])
                ->first();

            if (! $record instanceof Model) {
                $record = $modelClass::query()
                    ->get()
                    ->first(fn (Model $candidate) => $this->normalizeComparisonValue((string) ($candidate->{$nameColumn} ?? null)) === $this->normalizeComparisonValue($name));
            }
        }

        if ($record instanceof Model) {
            return $this->resolveCache[$cacheKey] = array_merge($default, [
                'status' => 'resolved',
                'catalog_id' => (int) $record->getKey(),
                'resolved_name' => (string) ($record->{$nameColumn} ?? $name),
            ]);
        }

        return $this->resolveCache[$cacheKey] = array_merge($default, [
            'status' => 'missing',
            'can_create' => $code !== null,
            'create_reason' => $code !== null
                ? null
                : "Se requiere {$codeColumn} para crear {$label}.",
        ]);
    }

    /**
     * @param  array<string, mixed>  $dependency
     * @return array<string, mixed>
     */
    private function formatMissingDependency(array $dependency): array
    {
        return [
            'type' => $dependency['type'],
            'label' => $dependency['label'],
            'code' => $dependency['code'],
            'name' => $dependency['name'],
            'critical' => (bool) ($dependency['critical'] ?? true),
            'can_create' => (bool) ($dependency['can_create'] ?? false),
            'create_reason' => $dependency['create_reason'] ?? null,
            'action_suggested' => ($dependency['can_create'] ?? false) ? 'create_catalog' : 'manual_resolution',
            'related_company_code' => $dependency['related_company_code'] ?? null,
            'related_company_name' => $dependency['related_company_name'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $dependency
     */
    private function buildMissingItemIdentity(array $dependency): string
    {
        return implode('|', [
            $dependency['type'] ?? '',
            $this->normalizeComparisonValue($dependency['code'] ?? null) ?? '',
            $this->normalizeComparisonValue($dependency['name'] ?? null) ?? '',
            $this->normalizeComparisonValue($dependency['related_company_code'] ?? null) ?? '',
            $this->normalizeComparisonValue($dependency['related_company_name'] ?? null) ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function ensureCatalogItemExists(string $type, array $item): bool
    {
        return match ($type) {
            'companies' => $this->ensureOperationalCompanyExists($item),
            'locations' => $this->ensureOperationalLocationExists($item),
            'razones_sociales' => $this->ensureSimpleCatalogExists($item, RazonSocial::class, 'cla_razon_social', 'nom_razon_social'),
            'registros_imss' => $this->ensureSimpleCatalogExists($item, RegistroImss::class, 'cla_reg_imss', 'nom_reg_imss'),
            'puestos' => $this->ensureSimpleCatalogExists($item, Puesto::class, 'cla_puesto', 'nom_puesto'),
            'centros_costo' => $this->ensureSimpleCatalogExists($item, CentroCosto::class, 'cla_centro_costo', 'nom_centro_costo'),
            'areas' => $this->ensureSimpleCatalogExists($item, Area::class, 'cla_area', 'nom_area'),
            'departamentos' => $this->ensureSimpleCatalogExists($item, Departamento::class, 'cla_depto', 'nom_departamento'),
            'ubicaciones_laborales' => $this->ensureSimpleCatalogExists($item, Ubicacion::class, 'cla_ubicacion', 'nom_ubicacion'),
            'periodos_pago' => $this->ensureSimpleCatalogExists($item, PeriodoPago::class, 'cla_periodo_pago', 'nom_periodo_pago'),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function ensureOperationalCompanyExists(array $item): bool
    {
        $resolution = $this->resolveOperationalCompany($item['code'] ?? null, $item['name'] ?? null);
        if (($resolution['status'] ?? null) === 'resolved') {
            return false;
        }

        $code = $this->cleanText($item['code'] ?? null);
        if ($code === null || ! Schema::hasTable('companies')) {
            return false;
        }

        $attributes = [
            'name' => $this->fallbackName($item['name'] ?? null, $code),
            'status' => 1,
        ];

        if (Schema::hasColumn('companies', 'fortia_company_id') && preg_match('/^\d+$/', $code) === 1) {
            $attributes['fortia_company_id'] = (int) $code;
        } elseif (Schema::hasColumn('companies', 'code')) {
            $attributes['code'] = $this->truncate($code, 20);
        } else {
            return false;
        }

        Company::query()->create($attributes);
        $this->resolveCache = [];

        return true;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function ensureOperationalLocationExists(array $item): bool
    {
        $companyDependency = $this->resolveOperationalCompany(
            $item['related_company_code'] ?? null,
            $item['related_company_name'] ?? null
        );
        $resolution = $this->resolveOperationalLocation(
            $item['code'] ?? null,
            $item['name'] ?? null,
            $companyDependency
        );

        if (($resolution['status'] ?? null) === 'resolved') {
            return false;
        }

        $code = $this->cleanText($item['code'] ?? null);
        if ($code === null || ! Schema::hasTable('locations')) {
            return false;
        }

        $attributes = [
            'name' => $this->fallbackName($item['name'] ?? null, $code),
            'status' => 1,
        ];

        if (Schema::hasColumn('locations', 'fortia_location_id') && preg_match('/^\d+$/', $code) === 1) {
            $attributes['fortia_location_id'] = (int) $code;
        } elseif (Schema::hasColumn('locations', 'code')) {
            $attributes['code'] = $this->truncate($code, 30);
        } else {
            return false;
        }

        if (Schema::hasColumn('locations', 'company_id')) {
            $company = $this->resolveCompanyForLocation($item);
            if ($company instanceof Company) {
                $attributes['company_id'] = $company->id;
            }
        }

        Location::query()->create($attributes);
        $this->resolveCache = [];

        return true;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveCompanyForLocation(array $item): ?Company
    {
        $resolution = $this->resolveOperationalCompany(
            $item['related_company_code'] ?? null,
            $item['related_company_name'] ?? null
        );

        if (($resolution['status'] ?? null) !== 'resolved' || empty($resolution['catalog_id'])) {
            return null;
        }

        return Company::query()->find((int) $resolution['catalog_id']);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  class-string<Model>  $modelClass
     */
    private function ensureSimpleCatalogExists(array $item, string $modelClass, string $codeColumn, string $nameColumn): bool
    {
        $code = $this->cleanText($item['code'] ?? null);
        if ($code === null) {
            return false;
        }

        if ($modelClass::query()->where($codeColumn, $code)->exists()) {
            return false;
        }

        $modelClass::query()->create([
            $codeColumn => $this->truncate($code, 50),
            $nameColumn => $this->truncate($this->fallbackName($item['name'] ?? null, $code), 255),
        ]);
        $this->resolveCache = [];

        return true;
    }

    private function normalizeComparisonValue(?string $value): ?string
    {
        $value = $this->cleanText($value);
        if ($value === null) {
            return null;
        }

        return mb_strtoupper(trim(Str::ascii($value)));
    }

    private function buildCacheKey(string $type, ?string ...$parts): string
    {
        return $type.'|'.implode('|', array_map(
            fn (?string $part) => $this->normalizeComparisonValue($part) ?? '',
            $parts
        ));
    }

    private function resolveForeignStoredValue(string $type, Company|Location $model): int|string|null
    {
        $candidates = $type === 'companies'
            ? ['fortia_company_id', 'external_id', 'legacy_code', 'code']
            : ['fortia_location_id', 'external_id', 'legacy_code', 'code'];

        foreach ($candidates as $candidate) {
            if ($model->{$candidate} !== null && $model->{$candidate} !== '') {
                return is_numeric($model->{$candidate})
                    ? (int) $model->{$candidate}
                    : (string) $model->{$candidate};
            }
        }

        return null;
    }

    private function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        $value = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function fallbackName(?string $name, string $code): string
    {
        return $this->cleanText($name) ?? "Sin nombre - {$code}";
    }

    private function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
