<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\CentroCosto;
use App\Models\Departamento;
use App\Models\Employee;
use App\Models\EmployeeDetail;
use App\Models\PeriodoPago;
use App\Models\Puesto;
use App\Models\RazonSocial;
use App\Models\RegistroImss;
use App\Models\Ubicacion;
use Database\Seeders\Concerns\ReadsCsvRows;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class EmployeeDetailsSeeder extends Seeder
{
    use ReadsCsvRows;

    public function run(): void
    {
        if (! Schema::hasTable('employee_details') || ! Schema::hasTable('employees')) {
            $this->command?->warn('Tablas employees/employee_details no disponibles. Se omite EmployeeDetailsSeeder.');

            return;
        }

        $sourcePath = base_path('database/datos/colaborador_detalle_mapeo_claves.csv');
        $rows = $this->readCsvRows($sourcePath);
        if ($rows === []) {
            $this->command?->warn('No se encontraron filas en el archivo de mapeo de detalles.');

            return;
        }

        $employeeByFortiaId = Employee::query()
            ->pluck('id', 'fortia_employee_id')
            ->mapWithKeys(fn ($id, $fortiaId) => [trim((string) $fortiaId) => (int) $id])
            ->all();

        $catalogMaps = [
            'razon_social_id' => RazonSocial::query()->pluck('id', 'cla_razon_social')->mapWithKeys(fn ($id, $code) => [trim((string) $code) => (int) $id])->all(),
            'registro_imss_id' => RegistroImss::query()->pluck('id', 'cla_reg_imss')->mapWithKeys(fn ($id, $code) => [trim((string) $code) => (int) $id])->all(),
            'puesto_id' => Puesto::query()->pluck('id', 'cla_puesto')->mapWithKeys(fn ($id, $code) => [trim((string) $code) => (int) $id])->all(),
            'centro_costo_id' => CentroCosto::query()->pluck('id', 'cla_centro_costo')->mapWithKeys(fn ($id, $code) => [trim((string) $code) => (int) $id])->all(),
            'area_id' => Area::query()->pluck('id', 'cla_area')->mapWithKeys(fn ($id, $code) => [trim((string) $code) => (int) $id])->all(),
            'departamento_id' => Departamento::query()->pluck('id', 'cla_depto')->mapWithKeys(fn ($id, $code) => [trim((string) $code) => (int) $id])->all(),
            'ubicacion_id' => Ubicacion::query()->pluck('id', 'cla_ubicacion')->mapWithKeys(fn ($id, $code) => [trim((string) $code) => (int) $id])->all(),
            'periodo_pago_id' => PeriodoPago::query()->pluck('id', 'cla_periodo_pago')->mapWithKeys(fn ($id, $code) => [trim((string) $code) => (int) $id])->all(),
        ];

        $csvToFk = [
            'CLA_RAZON_SOCIAL' => 'razon_social_id',
            'CLA_REG_IMSS' => 'registro_imss_id',
            'CLA_PUESTO' => 'puesto_id',
            'CLA_CENTRO_COSTO' => 'centro_costo_id',
            'CLA_AREA' => 'area_id',
            'CLA_DEPTO' => 'departamento_id',
            'CLA_UBICACION' => 'ubicacion_id',
            'CLA_PERIODO_PAGO' => 'periodo_pago_id',
        ];

        $summary = [
            'processed' => 0,
            'upserted' => 0,
            'skipped_without_employee' => 0,
            'skipped_missing_catalog' => 0,
            'skipped_invalid_row' => 0,
        ];

        $missingCatalogSamples = [];

        foreach ($rows as $row) {
            $summary['processed']++;

            $claTrab = $this->csvValue($row, 'CLA_TRAB');
            if ($claTrab === null) {
                $summary['skipped_invalid_row']++;

                continue;
            }

            $employeeId = $employeeByFortiaId[$claTrab] ?? null;
            if ($employeeId === null) {
                $summary['skipped_without_employee']++;

                continue;
            }

            $payload = [
                'cla_trab' => $claTrab,
            ];

            $missingCatalogs = [];

            foreach ($csvToFk as $csvColumn => $fkColumn) {
                $code = $this->csvValue($row, $csvColumn);

                if ($code === null) {
                    $payload[$fkColumn] = null;
                    continue;
                }

                $catalogId = $catalogMaps[$fkColumn][$code] ?? null;
                if ($catalogId === null) {
                    $missingCatalogs[] = $csvColumn.'='.$code;
                    continue;
                }

                $payload[$fkColumn] = $catalogId;
            }

            if ($missingCatalogs !== []) {
                $summary['skipped_missing_catalog']++;

                if (count($missingCatalogSamples) < 10) {
                    $missingCatalogSamples[] = [
                        'cla_trab' => $claTrab,
                        'missing' => implode(', ', $missingCatalogs),
                    ];
                }

                continue;
            }

            EmployeeDetail::query()->updateOrCreate(
                ['employee_id' => $employeeId],
                $payload
            );

            $summary['upserted']++;
        }

        $this->command?->info('EmployeeDetailsSeeder resumen: '.json_encode($summary, JSON_UNESCAPED_UNICODE));

        if ($missingCatalogSamples !== []) {
            $this->command?->warn('Muestras de catalogos faltantes en mapeo: '.json_encode($missingCatalogSamples, JSON_UNESCAPED_UNICODE));
        }
    }
}
