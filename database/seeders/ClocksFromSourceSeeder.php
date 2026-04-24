<?php

namespace Database\Seeders;

use App\Services\Fortia\ClockCatalogImportService;
use Illuminate\Database\Seeder;

class ClocksFromSourceSeeder extends Seeder
{
    public function run(): void
    {
        /** @var ClockCatalogImportService $service */
        $service = app(ClockCatalogImportService::class);
        $configuredCompanyId = config('fortia.company_id');
        $summary = $service->import(
            sourcePath: null,
            dryRun: false,
            defaultCompanyId: is_numeric($configuredCompanyId) ? (int) $configuredCompanyId : null
        );

        $this->command?->info('ClocksFromSourceSeeder: '.json_encode($summary, JSON_UNESCAPED_UNICODE));
    }
}
