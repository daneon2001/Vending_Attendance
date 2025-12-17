<?php

namespace App\Console\Commands;

use App\Services\FortiaMock\FortiaMockSyncService;
use Illuminate\Console\Command;

class FortiaMockSyncEmployees extends Command
{
    protected $signature = 'fortia-mock:sync-employees {--company_id=}';

    protected $description = 'Sincroniza empleados desde el mock de Fortia hacia la base local';

    public function __construct(protected FortiaMockSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $filters = [];
        if ($this->option('company_id')) {
            $filters['company_id'] = (int) $this->option('company_id');
        }

        try {
            $summary = $this->syncService->syncIncremental($filters);
        } catch (\Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info("Nuevos: {$summary['new']}");
        $this->info("Actualizados: {$summary['updated']}");
        $this->info("Cambios de estatus: {$summary['status_changed']}");

        $changes = $summary['changed'] ?? [];
        if (! empty($changes)) {
            $this->line('Cambios detectados:');
            $display = array_slice($changes, 0, 20);
            foreach ($display as $change) {
                $this->line(sprintf(
                    '- Empresa %s, empleado %s (%s): %s -> %s [%s]',
                    $change['company_id'],
                    $change['fortia_employee_id'],
                    $change['full_name'],
                    $change['old_status'] ?? 'N/A',
                    $change['new_status'],
                    $change['changed_at']
                ));
            }
            if (count($changes) > count($display)) {
                $this->line('... y '.(count($changes) - count($display)).' más');
            }
        }

        return self::SUCCESS;
    }
}
