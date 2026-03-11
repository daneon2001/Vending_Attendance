<?php

namespace Database\Seeders;

use App\Actions\EnsureSuperAdmin;
use Illuminate\Database\Seeder;

class EnsureAdminAccessSeeder extends Seeder
{
    public function run(): void
    {
        $result = EnsureSuperAdmin::run();

        if (! $result['user']) {
            $this->command?->warn('No se encontró usuario administrador para asegurar permisos.');

            return;
        }

        $this->command?->info(sprintf(
            'Rol %s sincronizado con %d permisos y asignado a %s.',
            $result['role']->name,
            $result['permission_count'],
            $result['user']->email,
        ));
    }
}
