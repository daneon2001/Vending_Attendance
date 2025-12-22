<?php

namespace App\Console\Commands;

use App\Actions\EnsureSuperAdmin;
use Illuminate\Console\Command;

class MakeAdminSuperCommand extends Command
{
    protected $signature = 'permissions:make-admin-super {--email=}';

    protected $description = 'Sincroniza el rol Administrador con todos los permisos y lo asigna al super usuario.';

    public function handle(): int
    {
        $result = EnsureSuperAdmin::run($this->option('email'));

        if (! $result['user']) {
            $this->error('No se encontró ningún usuario válido para elevar a super administrador.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Usuario %s ahora tiene el rol %s con %d permisos.',
            $result['user']->email,
            $result['role']->name,
            $result['permission_count'],
        ));

        return self::SUCCESS;
    }
}
