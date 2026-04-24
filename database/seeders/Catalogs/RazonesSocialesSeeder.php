<?php

namespace Database\Seeders\Catalogs;

use App\Models\RazonSocial;

class RazonesSocialesSeeder extends BaseCatalogCsvSeeder
{
    protected function tableName(): string
    {
        return 'razones_sociales';
    }

    protected function modelClass(): string
    {
        return RazonSocial::class;
    }

    protected function csvRelativePath(): string
    {
        return 'database/datos/catalogo_razones_sociales.csv';
    }

    protected function keyColumn(): string
    {
        return 'cla_razon_social';
    }

    protected function nameColumn(): string
    {
        return 'nom_razon_social';
    }
}
