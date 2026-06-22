<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\EmployeeStatusChange;
use App\Support\TabularDataReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EmployeeExcelImportService
{
    private ?bool $hasEmployeeDetailsTable = null;

    private ?bool $hasEmployeeImportMetadataTable = null;

    private ?bool $hasEmployeeStatusChangesTable = null;

    public function __construct(
        private readonly TabularDataReader $reader,
        private readonly EmployeeExcelCatalogResolutionService $catalogResolutionService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file): array
    {
        return $this->formatResult(
            $this->analyzeFile($file)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createMissingCatalogs(UploadedFile $file): array
    {
        $analysis = $this->analyzeFile($file);
        $creation = $this->catalogResolutionService->createMissingCatalogs($analysis['prepared_rows'] ?? []);
        $rechecked = $this->formatResult($this->analyzeFile($file));

        return [
            'message' => 'Catalogos creados correctamente. Se recalculo la validacion del archivo.',
            'created_catalogs' => $creation['summary'] ?? [],
            'created_total' => $creation['created_total'] ?? 0,
            'preview' => $rechecked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file): array
    {
        $analysis = $this->analyzeFile($file);
        $result = $this->formatResult($analysis);

        if (($result['can_import'] ?? false) !== true) {
            return $result;
        }

        $createdCount = 0;
        $updatedCount = 0;
        $terminationAppliedCount = 0;
        $terminationSkippedNotFoundCount = 0;
        $persistedRows = 0;
        $preparedRows = $analysis['prepared_rows'];
        $hasEmployeeDetailsTable = $this->hasEmployeeDetailsTable();
        $hasEmployeeImportMetadataTable = $this->hasEmployeeImportMetadataTable();
        $hasEmployeeStatusChangesTable = $this->hasEmployeeStatusChangesTable();
        $employeeCache = $this->loadEmployeesForImport($preparedRows);

        Log::info('employees.import.started', [
            'file_name' => $file->getClientOriginalName(),
            'total_rows' => (int) ($analysis['summary']['total_rows'] ?? 0),
            'valid_rows' => collect($preparedRows)->filter(function (array $row): bool {
                return ($row['errors'] ?? []) === []
                    && (($row['blocked_by_catalogs'] ?? false) === false);
            })->count(),
            'table_support' => [
                'employee_details' => $hasEmployeeDetailsTable,
                'employee_import_metadata' => $hasEmployeeImportMetadataTable,
                'employee_status_changes' => $hasEmployeeStatusChangesTable,
            ],
        ]);

        try {
            DB::transaction(function () use (
                $preparedRows,
                $file,
                &$createdCount,
                &$updatedCount,
                &$terminationAppliedCount,
                &$terminationSkippedNotFoundCount,
                &$persistedRows,
                &$employeeCache,
                $hasEmployeeDetailsTable,
                $hasEmployeeImportMetadataTable,
                $hasEmployeeStatusChangesTable
            ): void {
                foreach ($preparedRows as $preparedRow) {
                    if (($preparedRow['errors'] ?? []) !== []) {
                        continue;
                    }

                    if (($preparedRow['blocked_by_catalogs'] ?? false) === true) {
                        continue;
                    }

                    $persistedRows++;
                    $persisted = $this->persistRow(
                        preparedRow: $preparedRow,
                        fileName: $file->getClientOriginalName(),
                        employeeCache: $employeeCache,
                        hasEmployeeDetailsTable: $hasEmployeeDetailsTable,
                        hasEmployeeImportMetadataTable: $hasEmployeeImportMetadataTable,
                        hasEmployeeStatusChangesTable: $hasEmployeeStatusChangesTable
                    );

                    if ($persisted === 'termination_skipped') {
                        $terminationSkippedNotFoundCount++;
                    } elseif ($persisted === 'termination_applied') {
                        $terminationAppliedCount++;
                        $updatedCount++;
                    } elseif ($persisted === 'created') {
                        $createdCount++;
                    } else {
                        $updatedCount++;
                    }

                    if ($persistedRows % 500 === 0) {
                        Log::info('employees.import.progress', [
                            'file_name' => $file->getClientOriginalName(),
                            'processed_rows' => $persistedRows,
                            'created_count' => $createdCount,
                            'updated_count' => $updatedCount,
                            'termination_applied_count' => $terminationAppliedCount,
                            'termination_skipped_not_found_count' => $terminationSkippedNotFoundCount,
                        ]);
                    }
                }
            });
        } catch (\Throwable $exception) {
            Log::error('employees.import.failed', [
                'file_name' => $file->getClientOriginalName(),
                'processed_rows' => $persistedRows,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'termination_applied_count' => $terminationAppliedCount,
                'termination_skipped_not_found_count' => $terminationSkippedNotFoundCount,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]);

            throw $exception;
        }

        $result['message'] = 'Importacion completada correctamente.';
        $result['created_count'] = $createdCount;
        $result['updated_count'] = $updatedCount;
        $result['imported_count'] = $createdCount + $updatedCount;
        $result['termination_applied_count'] = $terminationAppliedCount;
        $result['termination_skipped_not_found_count'] = $terminationSkippedNotFoundCount;
        $result['normal_created_count'] = $createdCount;
        $result['normal_updated_count'] = max($updatedCount - $terminationAppliedCount, 0);
        $result['summary']['created_count'] = $createdCount;
        $result['summary']['updated_count'] = $updatedCount;
        $result['summary']['imported_count'] = $createdCount + $updatedCount;
        $result['summary']['termination_applied_count'] = $terminationAppliedCount;
        $result['summary']['termination_skipped_not_found_count'] = $terminationSkippedNotFoundCount;
        $result['summary']['normal_created_count'] = $createdCount;
        $result['summary']['normal_updated_count'] = max($updatedCount - $terminationAppliedCount, 0);

        Log::info('employees.import.completed', [
            'file_name' => $file->getClientOriginalName(),
            'processed_rows' => $persistedRows,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'termination_applied_count' => $terminationAppliedCount,
            'termination_skipped_not_found_count' => $terminationSkippedNotFoundCount,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeFile(UploadedFile $file): array
    {
        $path = $file->getPathname();
        $extension = $this->resolveUploadedExtension($file);
        $rows = is_string($path) && $path !== ''
            ? $this->reader->readRows($path, $extension)
            : [];

        $preRows = [];
        $duplicateCounter = [];

        foreach ($rows as $index => $row) {
            $values = $this->normalizeRowValues($row);

            if (! $this->isImportCandidateRow($values)) {
                continue;
            }

            $preRows[] = [
                'row_number' => $index + 2,
                'values' => $values,
            ];

            $claTrabKey = trim((string) ($values['cla_trab'] ?? ''));
            if ($claTrabKey !== '') {
                $duplicateCounter[$claTrabKey] = ($duplicateCounter[$claTrabKey] ?? 0) + 1;
            }
        }

        $existingEmployees = Employee::query()
            ->whereIn('fortia_employee_id', $this->extractExistingLookupIds($preRows))
            ->get()
            ->keyBy(fn (Employee $employee) => (string) $employee->fortia_employee_id);

        $preparedRows = [];
        foreach ($preRows as $preRow) {
            $preparedRows[] = $this->prepareRow($preRow, $duplicateCounter, $existingEmployees);
        }

        $catalogSummary = $this->catalogResolutionService->buildCatalogSummary($preparedRows);
        $totalRows = count($preparedRows);
        $errorRows = collect($preparedRows)->filter(fn (array $row) => $row['errors'] !== [])->count();
        $warningRows = collect($preparedRows)->filter(fn (array $row) => $row['warnings'] !== [])->count();
        $pendingByCatalogs = (int) ($catalogSummary['employees_pending'] ?? 0);
        $validRows = collect($preparedRows)->filter(function (array $row): bool {
            return $row['errors'] === [] && ($row['blocked_by_catalogs'] ?? false) === false;
        });
        $newRows = $validRows
            ->filter(fn (array $row) => ($row['action'] ?? null) === 'create' && ($row['operation'] ?? 'normal') === 'normal')
            ->count();
        $normalUpdateRows = $validRows
            ->filter(fn (array $row) => ($row['action'] ?? null) === 'update' && ($row['operation'] ?? 'normal') === 'normal')
            ->count();
        $terminationAppliedRows = $validRows
            ->filter(fn (array $row) => ($row['operation'] ?? null) === 'termination' && ($row['skip_import'] ?? false) === false)
            ->count();
        $terminationSkippedRows = $validRows
            ->filter(fn (array $row) => ($row['operation'] ?? null) === 'termination' && ($row['skip_import'] ?? false) === true)
            ->count();
        $updateRows = $normalUpdateRows + $terminationAppliedRows;

        return [
            'file_name' => $file->getClientOriginalName(),
            'summary' => [
                'total_rows' => $totalRows,
                'new_records' => $newRows,
                'create_count' => $newRows,
                'update_records' => $updateRows,
                'update_count' => $updateRows,
                'error_records' => $errorRows,
                'error_count' => $errorRows,
                'warning_records' => $warningRows,
                'catalog_missing_records' => (int) ($catalogSummary['missing_total'] ?? 0),
                'catalog_missing_types' => (int) ($catalogSummary['missing_types'] ?? 0),
                'catalog_creatable_records' => (int) ($catalogSummary['creatable_total'] ?? 0),
                'employees_pending_by_catalogs' => $pendingByCatalogs,
                'employees_resolvable_after_catalog_creation' => (int) ($catalogSummary['employees_resolvable_if_created'] ?? 0),
                'termination_applied_count' => $terminationAppliedRows,
                'termination_skipped_not_found_count' => $terminationSkippedRows,
                'normal_created_count' => $newRows,
                'normal_updated_count' => $normalUpdateRows,
            ],
            'catalogs' => $catalogSummary,
            'prepared_rows' => $preparedRows,
            'message' => $totalRows === 0
                ? 'El archivo no contiene filas con datos para importar.'
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function formatResult(array $analysis): array
    {
        $rows = collect($analysis['prepared_rows'] ?? [])
            ->map(function (array $row): array {
                return [
                    'row_number' => $row['row_number'],
                    'cla_trab' => $row['values']['cla_trab'] ?? null,
                    'name' => $row['values']['nombre'] ?? null,
                    'status' => $this->formatStatusLabel($row['employee_attributes']['status'] ?? $row['values']['estatus_trabajador'] ?? null),
                    'ubicacion' => $row['values']['nom_ubicacion'] ?? $row['values']['cla_ubicacion'] ?? null,
                    'centro_costo' => $row['values']['nom_centro_costo'] ?? $row['values']['cla_centro_costo'] ?? null,
                    'departamento' => $row['values']['nom_departamento'] ?? $row['values']['cla_depto'] ?? null,
                    'action' => $row['errors'] !== []
                        ? 'error'
                        : $row['action'],
                    'expected_action' => $row['action'],
                    'errors' => $row['errors'],
                    'warnings' => $row['warnings'],
                    'blocked_by_catalogs' => (bool) ($row['blocked_by_catalogs'] ?? false),
                    'can_be_resolved_by_catalog_creation' => (bool) ($row['can_be_resolved_by_catalog_creation'] ?? false),
                    'catalog_missing_types' => $row['catalog_missing_types'] ?? [],
                    'catalog_missing_labels' => collect($row['missing_catalogs'] ?? [])->pluck('label')->values()->all(),
                    'missing_catalogs' => $row['missing_catalogs'] ?? [],
                    'reasons' => array_values(array_filter(array_unique(array_merge(
                        $row['errors'],
                        collect($row['missing_catalogs'] ?? [])
                            ->map(function (array $dependency): string {
                                if (! $dependency['can_create']) {
                                    return $dependency['label'].': '.$dependency['create_reason'];
                                }

                                return $dependency['label'].' pendiente de alta.';
                            })
                            ->all(),
                        $row['warnings'],
                    )))),
                ];
            })
            ->values()
            ->all();

        $summary = $analysis['summary'] ?? [];
        $pendingByCatalogs = (int) ($summary['employees_pending_by_catalogs'] ?? 0);
        $errorRecords = (int) ($summary['error_records'] ?? 0);

        $message = $analysis['message'];
        if ($message === null) {
            if ($errorRecords > 0) {
                $message = 'Se detectaron errores. Corrige el archivo antes de importar.';
            } elseif ($pendingByCatalogs > 0) {
                $message = 'Se detectaron catalogos faltantes. Crea o resuelve los catalogos antes de importar.';
            } else {
                $message = 'Archivo validado correctamente.';
            }
        }

        return [
            'file_name' => $analysis['file_name'] ?? null,
            'message' => $message,
            'can_import' => ($summary['total_rows'] ?? 0) > 0
                && $errorRecords === 0
                && $pendingByCatalogs === 0,
            'summary' => $summary,
            'catalogs' => $analysis['catalogs'] ?? [],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $preRow
     * @param  array<string, int>  $duplicateCounter
     * @param  \Illuminate\Support\Collection<string, Employee>  $existingEmployees
     * @return array<string, mixed>
     */
    private function prepareRow(array $preRow, array $duplicateCounter, $existingEmployees): array
    {
        $values = $preRow['values'];
        $errors = [];

        $claTrab = $this->normalizeEmployeeCode($values['cla_trab'] ?? null);
        if ($claTrab === null) {
            $errors[] = 'CLA_TRAB es requerido y debe ser numerico.';
        }

        $claTrabKey = trim((string) ($values['cla_trab'] ?? ''));
        if ($claTrabKey !== '' && ($duplicateCounter[$claTrabKey] ?? 0) > 1) {
            $errors[] = 'CLA_TRAB duplicado dentro del archivo.';
        }

        $status = $this->normalizeEmployeeStatus($values['estatus_trabajador'] ?? null);
        if (($values['estatus_trabajador'] ?? null) !== null && $status === null) {
            $errors[] = 'ESTATUS_TRABAJADOR debe ser ACTIVO o BAJA.';
        }

        $existing = $claTrab !== null ? $existingEmployees->get((string) $claTrab) : null;
        $isTermination = $this->isTerminationStatus($status);
        $fechaBaja = $this->normalizeDate($values['fecha_baja'] ?? null, 'FECHA_BAJA', $errors);

        if ($isTermination) {
            return $this->prepareTerminationRow(
                preRow: $preRow,
                values: $values,
                existing: $existing,
                claTrab: $claTrab,
                status: $status,
                fechaBaja: $fechaBaja,
                errors: $errors
            );
        }

        $name = $this->cleanText($values['nombre'] ?? null);
        if ($name === null) {
            $errors[] = 'NOMBRE es requerido.';
        }

        $curp = $this->normalizeCurp($values['curp'] ?? null);
        if (($values['curp'] ?? null) !== null && $curp === null) {
            $errors[] = 'CURP invalido.';
        }

        $rfc = $this->normalizeRfc($values['rfc'] ?? null);
        if (($values['rfc'] ?? null) !== null && $rfc === null) {
            $errors[] = 'RFC invalido.';
        }

        $fechaIngreso = $this->normalizeDate($values['fecha_ing'] ?? null, 'FECHA_ING', $errors);
        $fechaIngresoGrupo = $this->normalizeDate($values['fecha_ing_grupo'] ?? null, 'FECHA_ING_GRUPO', $errors);
        $inicioContrato = $this->normalizeDate($values['inicio_contrato'] ?? null, 'INICIO_CONTRATO', $errors);
        $fechaNacimiento = $this->normalizeDate($values['fecha_nacimiento'] ?? null, 'FECHA_NACIMIENTO', $errors);
        $catalogResolution = $this->catalogResolutionService->resolveRowDependencies($values);
        $dependencies = $catalogResolution['dependencies'];

        $employeeAttributes = [
            'fortia_employee_id' => $claTrab,
            'company_id' => $dependencies['companies']['stored_value'] ?? null,
            'company_name' => $dependencies['companies']['resolved_name'] ?? $this->cleanText($values['nom_razon_social'] ?? null),
            'base_location_id' => $dependencies['locations']['stored_value'] ?? null,
            'base_location_name' => $dependencies['locations']['resolved_name'] ?? $this->cleanText($values['nom_ubicacion'] ?? null),
            'department_name' => $this->cleanText($values['nom_departamento'] ?? null),
            'full_name' => $name,
            'rfc' => $rfc,
            'imss_number' => $this->cleanText($values['num_imss'] ?? null),
            'curp' => $curp,
            'email_company' => $this->cleanText($values['correo_corporativo'] ?? null),
        ];

        if (! $existing) {
            $employeeAttributes['name'] = $name;
            $employeeAttributes['status'] = $status ?? 'A';
        } elseif ($status !== null) {
            $employeeAttributes['status'] = $status;
        }

        $detailAttributes = [
            'cla_trab' => $claTrab !== null ? (string) $claTrab : null,
            'razon_social_id' => $dependencies['razones_sociales']['catalog_id'] ?? null,
            'registro_imss_id' => $dependencies['registros_imss']['catalog_id'] ?? null,
            'puesto_id' => $dependencies['puestos']['catalog_id'] ?? null,
            'centro_costo_id' => $dependencies['centros_costo']['catalog_id'] ?? null,
            'area_id' => $dependencies['areas']['catalog_id'] ?? null,
            'departamento_id' => $dependencies['departamentos']['catalog_id'] ?? null,
            'ubicacion_id' => $dependencies['ubicaciones_laborales']['catalog_id'] ?? null,
            'periodo_pago_id' => $dependencies['periodos_pago']['catalog_id'] ?? null,
        ];

        $metadataPayload = array_filter([
            'cla_trab' => $claTrab !== null ? (string) $claTrab : null,
            'nombre' => $name,
            'curp' => $curp,
            'rfc' => $rfc,
            'num_imss' => $this->cleanText($values['num_imss'] ?? null),
            'cla_razon_social' => $this->cleanText($values['cla_razon_social'] ?? null),
            'nom_razon_social' => $this->cleanText($values['nom_razon_social'] ?? null),
            'cla_reg_imss' => $this->cleanText($values['cla_reg_imss'] ?? null),
            'nom_reg_imss' => $this->cleanText($values['nom_reg_imss'] ?? null),
            'fecha_ing' => $fechaIngreso,
            'fecha_ing_grupo' => $fechaIngresoGrupo,
            'cla_puesto' => $this->cleanText($values['cla_puesto'] ?? null),
            'nom_puesto' => $this->cleanText($values['nom_puesto'] ?? null),
            'cla_centro_costo' => $this->cleanText($values['cla_centro_costo'] ?? null),
            'nom_centro_costo' => $this->cleanText($values['nom_centro_costo'] ?? null),
            'cla_area' => $this->cleanText($values['cla_area'] ?? null),
            'nom_area' => $this->cleanText($values['nom_area'] ?? null),
            'cla_depto' => $this->cleanText($values['cla_depto'] ?? null),
            'nom_departamento' => $this->cleanText($values['nom_departamento'] ?? null),
            'cla_ubicacion' => $this->cleanText($values['cla_ubicacion'] ?? null),
            'nom_ubicacion' => $this->cleanText($values['nom_ubicacion'] ?? null),
            'cla_periodo_pago' => $this->cleanText($values['cla_periodo_pago'] ?? null),
            'nom_periodo_pago' => $this->cleanText($values['nom_periodo_pago'] ?? null),
            'roll_turno' => $this->cleanText($values['roll_turno'] ?? null),
            'correo_corporativo' => $this->cleanText($values['correo_corporativo'] ?? null),
            'correo_personal' => $this->cleanText($values['correo_personal'] ?? null),
            'antiguedad' => $this->cleanText($values['antiguedad'] ?? null),
            'sindicalizado' => $this->cleanText($values['sindicalizado'] ?? null),
            'tipo_de_contrato' => $this->cleanText($values['tipo_de_contrato'] ?? null),
            'inicio_contrato' => $inicioContrato,
            'dias_de_contrato' => $this->cleanText($values['dias_de_contrato'] ?? null),
            'calle' => $this->cleanText($values['calle'] ?? null),
            'colonia' => $this->cleanText($values['colonia'] ?? null),
            'codigo_postal' => $this->cleanText($values['codigo_postal'] ?? null),
            'ciudad' => $this->cleanText($values['ciudad'] ?? null),
            'municipio' => $this->cleanText($values['municipio'] ?? null),
            'nacionalidad' => $this->cleanText($values['nacionalidad'] ?? null),
            'pais_nacimiento' => $this->cleanText($values['pais_nacimiento'] ?? null),
            'telefono' => $this->cleanText($values['telefono'] ?? null),
            'fecha_nacimiento' => $fechaNacimiento,
            'genero' => $this->cleanText($values['genero'] ?? null),
            'codigo_postal_fiscal' => $this->cleanText($values['codigo_postal_fiscal'] ?? null),
            'estatus_trabajador' => $status,
            'fecha_baja' => $fechaBaja,
            'causa_baja' => $this->cleanText($values['causa_baja'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');

        $warnings = array_values(array_unique($catalogResolution['warnings'] ?? []));
        $missingCatalogs = $catalogResolution['missing_dependencies'] ?? [];
        $catalogMissingTypes = collect($missingCatalogs)->pluck('type')->unique()->values()->all();

        return [
            'row_number' => $preRow['row_number'],
            'action' => $existing ? 'update' : 'create',
            'operation' => 'normal',
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'values' => $values,
            'employee_id' => $existing?->id,
            'employee_attributes' => $employeeAttributes,
            'detail_attributes' => $detailAttributes,
            'metadata_payload' => $metadataPayload,
            'blocked_by_catalogs' => (bool) ($catalogResolution['blocking'] ?? false),
            'can_be_resolved_by_catalog_creation' => (bool) ($catalogResolution['resolvable'] ?? false),
            'missing_catalogs' => $missingCatalogs,
            'catalog_missing_types' => $catalogMissingTypes,
        ];
    }

    /**
     * @param  array<string, mixed>  $preRow
     * @param  array<string, string|null>  $values
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private function prepareTerminationRow(
        array $preRow,
        array $values,
        ?Employee $existing,
        ?int $claTrab,
        ?string $status,
        ?string $fechaBaja,
        array $errors
    ): array {
        $effectiveTerminationDate = $fechaBaja ?? CarbonImmutable::now()->format('Y-m-d');
        $warnings = [];
        $skipImport = false;
        $action = 'update';

        if (! $existing) {
            $skipImport = true;
            $action = 'skip';
            $warnings[] = 'Baja omitida: empleado no encontrado.';
        }

        return [
            'row_number' => $preRow['row_number'],
            'action' => $action,
            'operation' => 'termination',
            'skip_import' => $skipImport,
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'values' => $values,
            'employee_id' => $existing?->id,
            'employee_attributes' => [
                'fortia_employee_id' => $claTrab,
                'status' => $status ?? 'B',
            ],
            'detail_attributes' => [],
            'metadata_payload' => array_filter([
                'cla_trab' => $claTrab !== null ? (string) $claTrab : null,
                'estatus_trabajador' => $status ?? 'B',
                'fecha_baja' => $effectiveTerminationDate,
                'causa_baja' => $this->cleanText($values['causa_baja'] ?? null),
            ], fn ($value) => $value !== null && $value !== ''),
            'blocked_by_catalogs' => false,
            'can_be_resolved_by_catalog_creation' => false,
            'missing_catalogs' => [],
            'catalog_missing_types' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $preRows
     * @return array<int, int>
     */
    private function extractExistingLookupIds(array $preRows): array
    {
        return collect($preRows)
            ->map(fn (array $row) => $this->normalizeEmployeeCode($row['values']['cla_trab'] ?? null))
            ->filter(fn ($value) => $value !== null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $preparedRows
     * @return Collection<string, Employee>
     */
    private function loadEmployeesForImport(array $preparedRows): Collection
    {
        $lookupIds = collect($preparedRows)
            ->map(fn (array $row) => $row['employee_attributes']['fortia_employee_id'] ?? null)
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();

        if ($lookupIds === []) {
            return collect();
        }

        return Employee::query()
            ->whereIn('fortia_employee_id', $lookupIds)
            ->get()
            ->keyBy(fn (Employee $employee) => (string) $employee->fortia_employee_id);
    }

    /**
     * @param  array<string, mixed>  $preparedRow
     */
    private function persistRow(
        array $preparedRow,
        string $fileName,
        Collection &$employeeCache,
        bool $hasEmployeeDetailsTable,
        bool $hasEmployeeImportMetadataTable,
        bool $hasEmployeeStatusChangesTable
    ): string
    {
        if (($preparedRow['skip_import'] ?? false) === true) {
            return 'termination_skipped';
        }

        $fortiaEmployeeId = (string) ($preparedRow['employee_attributes']['fortia_employee_id'] ?? '');
        $employee = $employeeCache->get($fortiaEmployeeId);

        if (($preparedRow['operation'] ?? null) === 'termination') {
            if (! $employee instanceof Employee) {
                return 'termination_skipped';
            }

            $oldStatus = $employee->status;
            $employee->fill([
                'status' => $preparedRow['employee_attributes']['status'] ?? 'B',
            ]);
            $employee->save();

            $this->recordStatusChange(
                employee: $employee,
                oldStatus: $oldStatus,
                newStatus: $employee->status,
                effectiveDate: $preparedRow['metadata_payload']['fecha_baja'] ?? null,
                hasEmployeeStatusChangesTable: $hasEmployeeStatusChangesTable
            );

            $this->persistImportMetadata($employee, $preparedRow, $fileName, $hasEmployeeImportMetadataTable);
            $employeeCache->put($fortiaEmployeeId, $employee);

            return 'termination_applied';
        }

        $action = 'updated';
        if ($employee === null) {
            $employee = new Employee();
            $action = 'created';
        }

        $attributes = $preparedRow['employee_attributes'];
        if ($action === 'updated' && ! array_key_exists('status', $attributes)) {
            unset($attributes['status']);
        }

        if ($action === 'updated' && filled($employee->name)) {
            unset($attributes['name']);
        }

        $employee->fill($attributes);
        $employee->save();
        $employeeCache->put((string) $employee->fortia_employee_id, $employee);

        if ($hasEmployeeDetailsTable) {
            DB::table('employee_details')->updateOrInsert(
                ['employee_id' => $employee->id],
                array_merge($preparedRow['detail_attributes'], [
                    'employee_id' => $employee->id,
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }

        $this->persistImportMetadata($employee, $preparedRow, $fileName, $hasEmployeeImportMetadataTable);

        return $action;
    }

    /**
     * @param  array<string, mixed>  $preparedRow
     */
    private function persistImportMetadata(Employee $employee, array $preparedRow, string $fileName, bool $hasEmployeeImportMetadataTable): void
    {
        if (! $hasEmployeeImportMetadataTable) {
            return;
        }

        DB::table('employee_import_metadata')->updateOrInsert(
            ['employee_id' => $employee->id],
            [
                'source' => 'employees_excel',
                'source_file_name' => $fileName,
                'payload' => json_encode($preparedRow['metadata_payload'], JSON_UNESCAPED_UNICODE),
                'imported_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function recordStatusChange(
        Employee $employee,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $effectiveDate,
        bool $hasEmployeeStatusChangesTable
    ): void
    {
        if (
            $oldStatus === $newStatus
            || ! $hasEmployeeStatusChangesTable
            || ! is_numeric($employee->company_id)
            || ! is_numeric($employee->fortia_employee_id)
        ) {
            return;
        }

        $effectiveAt = $effectiveDate !== null
            ? CarbonImmutable::parse($effectiveDate)
            : CarbonImmutable::now();

        EmployeeStatusChange::query()->create([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_at' => $effectiveAt->toDateTimeString(),
            'source' => 'employees_excel',
            'meta' => [
                'remote_updated_at' => $effectiveAt->toIso8601String(),
            ],
        ]);
    }

    private function hasEmployeeDetailsTable(): bool
    {
        return $this->hasEmployeeDetailsTable ??= Schema::hasTable('employee_details');
    }

    private function hasEmployeeImportMetadataTable(): bool
    {
        return $this->hasEmployeeImportMetadataTable ??= Schema::hasTable('employee_import_metadata');
    }

    private function hasEmployeeStatusChangesTable(): bool
    {
        return $this->hasEmployeeStatusChangesTable ??= Schema::hasTable('employee_status_changes');
    }

    private function isImportCandidateRow(array $values): bool
    {
        return $this->cleanText($values['cla_trab'] ?? null) !== null
            || $this->cleanText($values['nombre'] ?? null) !== null;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string|null>
     */
    private function normalizeRowValues(array $row): array
    {
        return [
            'cla_trab' => $this->rowValue($row, ['CLA_TRAB']),
            'nombre' => $this->rowValue($row, ['NOMBRE']),
            'curp' => $this->rowValue($row, ['CURP']),
            'rfc' => $this->rowValue($row, ['RFC']),
            'num_imss' => $this->rowValue($row, ['NUM_IMSS']),
            'cla_razon_social' => $this->rowValue($row, ['CLA_RAZON_SOCIAL']),
            'nom_razon_social' => $this->rowValue($row, ['NOM_RAZON_SOCIAL']),
            'cla_reg_imss' => $this->rowValue($row, ['CLA_REG_IMSS']),
            'nom_reg_imss' => $this->rowValue($row, ['NOM_REG_IMSS']),
            'fecha_ing' => $this->rowValue($row, ['FECHA_ING']),
            'fecha_ing_grupo' => $this->rowValue($row, ['FECHA_ING_GRUPO']),
            'cla_puesto' => $this->rowValue($row, ['CLA_PUESTO']),
            'nom_puesto' => $this->rowValue($row, ['NOM_PUESTO']),
            'cla_centro_costo' => $this->rowValue($row, ['CLA_CENTRO_COSTO']),
            'nom_centro_costo' => $this->rowValue($row, ['NOM_CENTRO_COSTO']),
            'cla_area' => $this->rowValue($row, ['CLA_AREA']),
            'nom_area' => $this->rowValue($row, ['NOM_AREA']),
            'cla_depto' => $this->rowValue($row, ['CLA_DEPTO']),
            'nom_departamento' => $this->rowValue($row, ['NOM_DEPARTAMENTO']),
            'cla_ubicacion' => $this->rowValue($row, ['CLA_UBICACION']),
            'nom_ubicacion' => $this->rowValue($row, ['NOM_UBICACION']),
            'cla_periodo_pago' => $this->rowValue($row, ['CLA_PERIODO_PAGO']),
            'nom_periodo_pago' => $this->rowValue($row, ['NOM_PERIODO_PAGO']),
            'roll_turno' => $this->rowValue($row, ['ROLL_TURNO']),
            'correo_corporativo' => $this->rowValue($row, ['CORREO_CORPORATIVO']),
            'correo_personal' => $this->rowValue($row, ['CORREO_PERSONAL']),
            'antiguedad' => $this->rowValue($row, ['ANTIGUEDAD']),
            'sindicalizado' => $this->rowValue($row, ['SINDICALIZADO']),
            'tipo_de_contrato' => $this->rowValue($row, ['TIPO_DE_CONTRATO']),
            'inicio_contrato' => $this->rowValue($row, ['INICIO_CONTRATO']),
            'dias_de_contrato' => $this->rowValue($row, ['DIAS_DE_CONTRATO']),
            'calle' => $this->rowValue($row, ['CALLE']),
            'colonia' => $this->rowValue($row, ['COLONIA']),
            'codigo_postal' => $this->rowValue($row, ['CODIGO_POSTAL']),
            'ciudad' => $this->rowValue($row, ['CIUDAD']),
            'municipio' => $this->rowValue($row, ['MUNICIPIO']),
            'nacionalidad' => $this->rowValue($row, ['NACIONALIDAD']),
            'pais_nacimiento' => $this->rowValue($row, ['PAIS_NACIMIENTO']),
            'telefono' => $this->rowValue($row, ['TELEFONO']),
            'fecha_nacimiento' => $this->rowValue($row, ['FECHA_NACIMIENTO']),
            'genero' => $this->rowValue($row, ['GENERO']),
            'codigo_postal_fiscal' => $this->rowValue($row, ['CODIGO_POSTAL_FISCAL']),
            'estatus_trabajador' => $this->rowValue($row, ['ESTATUS_TRABAJADOR']),
            'fecha_baja' => $this->rowValue($row, ['FECHA_BAJA']),
            'causa_baja' => $this->rowValue($row, ['CAUSA_BAJA']),
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<int, string>  $candidates
     */
    private function rowValue(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeHeader($candidate);
            $value = $this->cleanText($row[$normalized] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        $value = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}"], ' ', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return strtoupper($value);
    }

    private function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = (string) $value;
        $normalized = str_replace("\xEF\xBB\xBF", '', $normalized);
        $normalized = preg_replace('/^\x{FEFF}/u', '', $normalized) ?? $normalized;
        $normalized = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}"], ' ', $normalized);
        $normalized = trim($normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    private function normalizeEmployeeCode(?string $value): ?int
    {
        $value = $this->cleanText($value);
        if ($value === null) {
            return null;
        }

        $value = str_replace(',', '', $value);

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (preg_match('/^\d+\.0+$/', $value) === 1) {
            return (int) strstr($value, '.', true);
        }

        return null;
    }

    private function normalizeCurp(?string $value): ?string
    {
        $value = $this->cleanText($value);
        if ($value === null) {
            return null;
        }

        $value = strtoupper($value);

        return preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $value) === 1
            ? $value
            : null;
    }

    private function normalizeRfc(?string $value): ?string
    {
        $value = $this->cleanText($value);
        if ($value === null) {
            return null;
        }

        $value = strtoupper($value);

        return preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $value) === 1
            ? $value
            : null;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function normalizeDate(?string $value, string $field, array &$errors): ?string
    {
        $value = $this->cleanText($value);
        if ($value === null) {
            return null;
        }

        try {
            if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
                $days = (int) floor((float) $value);
                if ($days <= 0) {
                    $errors[] = "{$field} no tiene una fecha valida.";

                    return null;
                }

                return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')
                    ->addDays($days)
                    ->format('Y-m-d');
            }

            foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'd/m/y', 'd-m-y'] as $format) {
                $date = CarbonImmutable::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            }
        } catch (\Throwable) {
            // Fall through to validation error.
        }

        $errors[] = "{$field} no tiene un formato valido.";

        return null;
    }

    private function normalizeEmployeeStatus(?string $value): ?string
    {
        $value = $this->cleanText($value);
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtoupper($value);

        return match ($normalized) {
            'A', 'ACTIVO', 'ACTIVE' => 'A',
            'B', 'BAJA', 'INACTIVO', 'INACTIVE' => 'B',
            default => null,
        };
    }

    private function isTerminationStatus(?string $status): bool
    {
        return $status === 'B';
    }

    private function formatStatusLabel(mixed $value): ?string
    {
        $normalized = $this->normalizeEmployeeStatus(is_string($value) ? $value : null);

        return match ($normalized) {
            'A' => 'ACTIVO',
            'B' => 'BAJA',
            default => $this->cleanText(is_scalar($value) ? (string) $value : null),
        };
    }

    private function resolveUploadedExtension(UploadedFile $file): string
    {
        $clientExtension = strtolower(trim((string) $file->getClientOriginalExtension()));
        if ($clientExtension !== '') {
            return $clientExtension;
        }

        $clientNameExtension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($clientNameExtension !== '') {
            return $clientNameExtension;
        }

        return strtolower((string) pathinfo($file->getPathname(), PATHINFO_EXTENSION));
    }
}
