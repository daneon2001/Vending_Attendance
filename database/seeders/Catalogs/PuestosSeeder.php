<?php

namespace Database\Seeders\Catalogs;

use App\Models\Puesto;

class PuestosSeeder extends BaseCatalogCsvSeeder
{
    protected function tableName(): string
    {
        return 'puestos';
    }

    protected function modelClass(): string
    {
        return Puesto::class;
    }

    protected function csvRelativePath(): string
    {
        return 'database/datos/catalogo_puestos.csv';
    }

    protected function keyColumn(): string
    {
        return 'cla_puesto';
    }

    protected function nameColumn(): string
    {
        return 'nom_puesto';
    }
}
