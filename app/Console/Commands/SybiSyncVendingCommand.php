<?php

namespace App\Console\Commands;

use App\Integrations\Sybi\SybiVendingApiException;
use App\Integrations\Sybi\SybiVendingRejection;
use App\Services\Vending\SybiVendingSyncService;
use Illuminate\Console\Command;

class SybiSyncVendingCommand extends Command
{
    protected $signature = 'sybi:sync-vending
        {--dry-run : Validate and report impact without writing any local data}
        {--show-rejections : Show sanitized per-record rejection diagnostics}';

    protected $description = 'Synchronize the vending-machine catalog from the read-only SYBIML API';

    public function handle(SybiVendingSyncService $service): int
    {
        try {
            $summary = $service->sync((bool) $this->option('dry-run'));
        } catch (SybiVendingApiException $exception) {
            $this->components->error(sprintf(
                'SYBIML sync failed: %s%s',
                $exception->errorCode->value,
                $exception->httpStatus !== null ? ' (HTTP '.$exception->httpStatus.')' : '',
            ));

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $summary['dry_run'] ? 'DRY RUN' : 'WRITE'],
            ['HTTP status', $summary['http_status']],
            ['Received', $summary['received_total']],
            ['Source candidates', $summary['source_candidates']],
            ['Source created', $summary['source_created']],
            ['Source updated', $summary['source_updated']],
            ['Source unchanged', $summary['source_unchanged']],
            ['Source invalid', $summary['source_invalid']],
            ['Operational ready', $summary['operational_ready']],
            ['Operational created', $summary['operational_created']],
            ['Operational updated', $summary['operational_updated']],
            ['Operational unchanged', $summary['operational_unchanged']],
            ['Operational incomplete', $summary['operational_incomplete']],
            ['Identifier conflicts', $summary['operational_conflicts']],
            ['Operational invalid', $summary['operational_invalid']],
            ['Source missing', $summary['operational_missing']],
            ['Duration ms', $summary['duration_ms']],
        ]);

        foreach ($summary['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        if ($this->option('show-rejections') && $summary['rejections'] !== []) {
            $this->newLine();
            $this->components->info('Rejected records');
            $this->table(
                ['Index', 'SYBI ID', 'Vending ID', 'Field', 'Code', 'Reason'],
                collect($summary['rejections'])
                    ->map(fn (SybiVendingRejection $rejection): array => $rejection->consoleRow())
                    ->all(),
            );
        }

        return self::SUCCESS;
    }
}
