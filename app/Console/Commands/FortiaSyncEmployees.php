<?php

namespace App\Console\Commands;

use App\Services\Fortia\CatalogAlignmentService;
use App\Services\Fortia\FortiaEmployeeService;
use App\Services\FortiaMock\FortiaMockSyncService;
use Database\Seeders\EmployeeDetailsSeeder;
use Illuminate\Console\Command;

class FortiaSyncEmployees extends Command
{
    protected $signature = 'fortia:sync-employees
        {--company_id= : Filtra por company_id}
        {--fortia_employee_id= : Sincroniza solo un empleado por clave Fortia}
        {--skip-catalog-sync : Omite sincronizacion de companies/locations}
        {--skip-details : Omite ejecucion de EmployeeDetailsSeeder}';

    protected $description = 'Sincroniza employees desde Fortia/Fortia mock y opcionalmente alinea catalogos operativos.';

    public function __construct(
        private readonly FortiaEmployeeService $fortiaService,
        private readonly FortiaMockSyncService $mockSyncService,
        private readonly CatalogAlignmentService $alignmentService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filters = [];
        if ($this->option('company_id') !== null) {
            $filters['company_id'] = (int) $this->option('company_id');
        }

        $syncMode = $this->fortiaService->describeMode();
        $this->line('Modo de sync: '.json_encode($syncMode, JSON_UNESCAPED_UNICODE));

        try {
            if ($this->fortiaService->usingMockMode()) {
                $summary = $this->mockSyncService->syncIncremental($filters);
            } elseif ($this->option('fortia_employee_id') !== null) {
                $summary = $this->fortiaService->syncEmployeeById((int) $this->option('fortia_employee_id'));
            } else {
                $summary = $this->fortiaService->syncEmployees($filters);
            }
        } catch (\Throwable $exception) {
            $this->error('Error en sincronizacion de employees: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Resumen employees: '.json_encode($summary, JSON_UNESCAPED_UNICODE));

        if (! (bool) $this->option('skip-catalog-sync')) {
            $catalogSummary = $this->alignmentService->syncOperationalCatalogs(false);
            $this->line('Resumen catalogos: '.json_encode($catalogSummary, JSON_UNESCAPED_UNICODE));
        }

        if (! (bool) $this->option('skip-details')) {
            $this->call('db:seed', [
                '--class' => EmployeeDetailsSeeder::class,
                '--no-interaction' => true,
            ]);
        }

        return self::SUCCESS;
    }
}
