<?php

namespace App\Console\Commands;

use App\Services\Fortia\CatalogAlignmentService;
use Illuminate\Console\Command;

class FortiaSyncOperationalCatalogs extends Command
{
    protected $signature = 'fortia:sync-operational-catalogs {--dry-run : Solo calcula cambios, no guarda nada}';

    protected $description = 'Sincroniza companies y locations operativos desde employees con llaves externas Fortia.';

    public function __construct(private readonly CatalogAlignmentService $alignmentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $this->alignmentService->syncOperationalCatalogs($dryRun);

        $this->line('Modo: '.$summary['mode']);
        $this->newLine();

        $this->line('Companies');
        $this->table(
            ['processed', 'inserted', 'updated', 'unchanged', 'skipped_invalid', 'skipped_no_safe_external_key', 'external_column'],
            [[
                $summary['companies']['processed'] ?? 0,
                $summary['companies']['inserted'] ?? 0,
                $summary['companies']['updated'] ?? 0,
                $summary['companies']['unchanged'] ?? 0,
                $summary['companies']['skipped_invalid'] ?? 0,
                $summary['companies']['skipped_no_safe_external_key'] ?? 0,
                $summary['companies']['external_column'] ?? null,
            ]]
        );

        $this->newLine();
        $this->line('Locations');
        $this->table(
            ['processed', 'inserted', 'updated', 'unchanged', 'skipped_invalid', 'unresolved_company', 'skipped_no_safe_external_key', 'external_column'],
            [[
                $summary['locations']['processed'] ?? 0,
                $summary['locations']['inserted'] ?? 0,
                $summary['locations']['updated'] ?? 0,
                $summary['locations']['unchanged'] ?? 0,
                $summary['locations']['skipped_invalid'] ?? 0,
                $summary['locations']['unresolved_company'] ?? 0,
                $summary['locations']['skipped_no_safe_external_key'] ?? 0,
                $summary['locations']['external_column'] ?? null,
            ]]
        );

        return self::SUCCESS;
    }
}
