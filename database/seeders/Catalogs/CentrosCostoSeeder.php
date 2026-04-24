<?php

namespace Database\Seeders\Catalogs;

use App\Models\CentroCosto;

class CentrosCostoSeeder extends BaseCatalogCsvSeeder
{
    protected function tableName(): string
    {
        return 'centros_costo';
    }

    protected function modelClass(): string
    {
        return CentroCosto::class;
    }

    protected function csvRelativePath(): string
    {
        return 'database/datos/catalogo_centros_costo.csv';
    }

    protected function keyColumn(): string
    {
        return 'cla_centro_costo';
    }

    protected function nameColumn(): string
    {
        return 'nom_centro_costo';
    }
}
