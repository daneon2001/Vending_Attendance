<?php

namespace Database\Seeders\Catalogs;

use App\Models\Departamento;

class DepartamentosSeeder extends BaseCatalogCsvSeeder
{
    protected function tableName(): string
    {
        return 'departamentos';
    }

    protected function modelClass(): string
    {
        return Departamento::class;
    }

    protected function csvRelativePath(): string
    {
        return 'database/datos/catalogo_departamentos.csv';
    }

    protected function keyColumn(): string
    {
        return 'cla_depto';
    }

    protected function nameColumn(): string
    {
        return 'nom_departamento';
    }
}
