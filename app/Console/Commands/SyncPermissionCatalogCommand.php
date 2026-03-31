<?php

namespace App\Console\Commands;

use App\Actions\SyncPermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissionCatalogCommand extends Command
{
    protected $signature = 'permissions:sync-catalog';

    protected $description = 'Sincroniza el catalogo base de permisos desde config/permissions.php.';

    public function handle(): int
    {
        $permissionsMap = SyncPermissionCatalog::run();

        $modules = count($permissionsMap);
        $permissions = collect($permissionsMap)
            ->flatten()
            ->unique()
            ->count();

        $this->info(sprintf(
            'Catalogo de permisos sincronizado: %d modulos, %d permisos.',
            $modules,
            $permissions,
        ));

        return self::SUCCESS;
    }
}
