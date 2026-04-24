<?php

namespace App\Console\Commands;

use App\Services\Fortia\CatalogAlignmentService;
use Illuminate\Console\Command;

class FortiaAuditCatalogAlignment extends Command
{
    protected $signature = 'fortia:audit-catalog-alignment {--sample=5 : Numero maximo de ejemplos por inconsistencia} {--json : Salida en JSON}';

    protected $description = 'Audita consistencia entre employees, catalogos operativos y employee_details.';

    public function __construct(private readonly CatalogAlignmentService $alignmentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sample = (int) $this->option('sample');
        $report = $this->alignmentService->auditAlignment($sample);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('Referencias de match');
        $this->table(
            ['company_column_for_employee_match', 'location_column_for_employee_match', 'legacy_table_empleados_exists'],
            [[
                $report['references']['company_column_for_employee_match'] ?? null,
                $report['references']['location_column_for_employee_match'] ?? null,
                ($report['references']['legacy_table_empleados_exists'] ?? false) ? 'YES' : 'NO',
            ]]
        );

        $this->newLine();
        $this->line('Conteos de auditoria');
        $rows = [];
        foreach (($report['counts'] ?? []) as $metric => $value) {
            $rows[] = [$metric, $value];
        }
        $this->table(['metric', 'value'], $rows);

        foreach (($report['samples'] ?? []) as $sampleName => $samples) {
            if (empty($samples)) {
                continue;
            }

            $this->newLine();
            $this->line('Muestras: '.$sampleName);
            $this->line(json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
