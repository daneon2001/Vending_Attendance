<?php

namespace Database\Seeders\Catalogs;

use App\Models\RegistroImss;

class RegistrosImssSeeder extends BaseCatalogCsvSeeder
{
    protected function tableName(): string
    {
        return 'registros_imss';
    }

    protected function modelClass(): string
    {
        return RegistroImss::class;
    }

    protected function csvRelativePath(): string
    {
        return 'database/datos/catalogo_registros_imss.csv';
    }

    protected function keyColumn(): string
    {
        return 'cla_reg_imss';
    }

    protected function nameColumn(): string
    {
        return 'nom_reg_imss';
    }
}
