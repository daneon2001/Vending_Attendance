<?php

namespace App\Console\Commands;

use App\Actions\EnsureSuperAdmin;
use Illuminate\Console\Command;

class EnsureAdminPermissions extends Command
{
    protected $signature = 'permissions:fix-admin {--email=}';

    protected $description = 'Garantiza que el usuario administrador tenga el rol y permisos completos.';

    public function handle(): int
    {
        $result = EnsureSuperAdmin::run($this->option('email'));

        if (! $result['user']) {
            $this->error('No se encontró ningún usuario para asignar como administrador.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Rol %s asignado a %s (%d permisos).',
            $result['role']->name,
            $result['user']->email,
            $result['permission_count'],
        ));

        return self::SUCCESS;
    }
}
