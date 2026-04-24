<?php

namespace Database\Seeders\Catalogs;

use App\Models\Area;

class AreasSeeder extends BaseCatalogCsvSeeder
{
    protected function tableName(): string
    {
        return 'areas';
    }

    protected function modelClass(): string
    {
        return Area::class;
    }

    protected function csvRelativePath(): string
    {
        return 'database/datos/catalogo_areas.csv';
    }

    protected function keyColumn(): string
    {
        return 'cla_area';
    }

    protected function nameColumn(): string
    {
        return 'nom_area';
    }
}
