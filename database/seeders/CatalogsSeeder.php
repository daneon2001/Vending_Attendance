<?php

namespace Database\Seeders;

use Database\Seeders\Catalogs\AreasSeeder;
use Database\Seeders\Catalogs\CentrosCostoSeeder;
use Database\Seeders\Catalogs\DepartamentosSeeder;
use Database\Seeders\Catalogs\PeriodosPagoSeeder;
use Database\Seeders\Catalogs\PuestosSeeder;
use Database\Seeders\Catalogs\RazonesSocialesSeeder;
use Database\Seeders\Catalogs\RegistrosImssSeeder;
use Database\Seeders\Catalogs\UbicacionesSeeder;
use Illuminate\Database\Seeder;

class CatalogsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RazonesSocialesSeeder::class,
            RegistrosImssSeeder::class,
            PuestosSeeder::class,
            CentrosCostoSeeder::class,
            AreasSeeder::class,
            DepartamentosSeeder::class,
            UbicacionesSeeder::class,
            PeriodosPagoSeeder::class,
        ]);
    }
}
