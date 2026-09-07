<?php

namespace App\Console\Commands;

use App\Services\Employees\FortiaEmployeeSyncService;
use Illuminate\Console\Command;

class FortiaSyncEmployees extends Command
{
    protected $signature = 'fortia:sync-employees
        {--dry-run : Fetch, validate and calculate diff without any writes}
        {--apply : Explicitly apply after reviewing a dry-run; server write gate must also be enabled}
        {--skip-catalog-sync : Compatibility flag; catalog writes are no longer performed}
        {--skip-details : Compatibility flag; personal details are never imported}
        {--company_id= : Source company filter}
        {--fortia_employee_id= : Source employee number filter}';

    protected $description = 'Project minimal Fortia employee identity; defaults to dry-run.';

    public function handle(FortiaEmployeeSyncService $service): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use --dry-run or --apply, not both.');

            return self::INVALID;
        }
        $filters = array_filter([
            'company_id' => $this->option('company_id'),
            'fortia_employee_id' => $this->option('fortia_employee_id'),
        ], fn ($value) => $value !== null);
        try {
            $dryRun = ! $this->option('apply');
            $summary = $service->sync($dryRun, $filters);
            $this->line(json_encode(['dry_run' => $dryRun, ...$summary], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
