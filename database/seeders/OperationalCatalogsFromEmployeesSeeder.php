<?php

namespace Database\Seeders;

use App\Services\Fortia\CatalogAlignmentService;
use Illuminate\Database\Seeder;

class OperationalCatalogsFromEmployeesSeeder extends Seeder
{
    public function run(): void
    {
        /** @var CatalogAlignmentService $service */
        $service = app(CatalogAlignmentService::class);
        $summary = $service->syncOperationalCatalogs(false);

        $this->command?->info('OperationalCatalogsFromEmployeesSeeder: '.json_encode($summary, JSON_UNESCAPED_UNICODE));
    }
}
