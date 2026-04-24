<?php

namespace App\Console\Commands;

use App\Services\Fortia\ClockCatalogImportService;
use Illuminate\Console\Command;

class FortiaAuditClocks extends Command
{
    protected $signature = 'fortia:audit-clocks {--sample=10 : Numero maximo de pendientes en muestra} {--json : Salida en JSON}';

    protected $description = 'Audita integridad y cobertura operativa del catalogo clocks.';

    public function __construct(private readonly ClockCatalogImportService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->service->audit((int) $this->option('sample'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows = [];
        foreach (($report['counts'] ?? []) as $metric => $value) {
            $rows[] = [$metric, $value];
        }

        $this->line('Diagnostico de clocks');
        $this->table(['metric', 'value'], $rows);

        if (! empty($report['samples']['pending_location_assignment'] ?? [])) {
            $this->newLine();
            $this->line('Muestra de relojes sin unidad:');
            $this->line(json_encode($report['samples']['pending_location_assignment'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
