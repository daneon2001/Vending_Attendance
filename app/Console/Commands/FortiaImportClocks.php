<?php

namespace App\Console\Commands;

use App\Services\Fortia\ClockCatalogImportService;
use Illuminate\Console\Command;

class FortiaImportClocks extends Command
{
    protected $signature = 'fortia:import-clocks
        {--source= : Ruta absoluta o relativa de CSV/XLSX}
        {--default-company-id= : Company ID fallback cuando Empresa no coincide}
        {--dry-run : Simula importacion sin guardar}';

    protected $description = 'Importa catalogo inicial de relojes en clocks desde fuente Fortia (CSV/XLSX).';

    public function __construct(private readonly ClockCatalogImportService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->service->import(
            sourcePath: $this->option('source') ? (string) $this->option('source') : null,
            dryRun: (bool) $this->option('dry-run'),
            defaultCompanyId: is_numeric($this->option('default-company-id'))
                ? (int) $this->option('default-company-id')
                : null
        );

        if (($summary['skipped_reason'] ?? null) === 'source_not_found') {
            $this->warn('No se encontro archivo fuente. Se buscaron: database/datos/relojes_fuente.csv, Relojes.csv, Relojes.xlsx');
        }

        $this->line('Resumen importacion de relojes');
        $this->table(
            ['mode', 'source', 'default_company_id', 'processed', 'inserted', 'updated', 'unchanged', 'skipped_invalid', 'skipped_without_company'],
            [[
                $summary['mode'] ?? null,
                $summary['source'] ?? null,
                $summary['default_company_id'] ?? null,
                $summary['processed'] ?? 0,
                $summary['inserted'] ?? 0,
                $summary['updated'] ?? 0,
                $summary['unchanged'] ?? 0,
                $summary['skipped_invalid'] ?? 0,
                $summary['skipped_without_company'] ?? 0,
            ]]
        );

        if (! empty($summary['samples']['without_company'] ?? [])) {
            $this->newLine();
            $this->warn('Muestras sin company match:');
            $this->line(json_encode($summary['samples']['without_company'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if (! empty($summary['samples']['invalid_rows'] ?? [])) {
            $this->newLine();
            $this->warn('Muestras invalidas:');
            $this->line(json_encode($summary['samples']['invalid_rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if (! empty($summary['suggested_locations'] ?? [])) {
            $this->newLine();
            $this->line('Sugerencias de unidad (solo reporte, no asignadas):');
            $this->line(json_encode($summary['suggested_locations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
