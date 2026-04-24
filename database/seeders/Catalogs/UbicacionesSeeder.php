<?php

namespace Database\Seeders\Catalogs;

use App\Models\Ubicacion;

class UbicacionesSeeder extends BaseCatalogCsvSeeder
{
    protected function tableName(): string
    {
        return 'ubicaciones';
    }

    protected function modelClass(): string
    {
        return Ubicacion::class;
    }

    protected function csvRelativePath(): string
    {
        return 'database/datos/catalogo_ubicaciones.csv';
    }

    protected function keyColumn(): string
    {
        return 'cla_ubicacion';
    }

    protected function nameColumn(): string
    {
        return 'nom_ubicacion';
    }
}
