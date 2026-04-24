<?php

namespace Database\Seeders\Catalogs;

use App\Models\PeriodoPago;

class PeriodosPagoSeeder extends BaseCatalogCsvSeeder
{
    protected function tableName(): string
    {
        return 'periodos_pago';
    }

    protected function modelClass(): string
    {
        return PeriodoPago::class;
    }

    protected function csvRelativePath(): string
    {
        return 'database/datos/catalogo_periodos_pago.csv';
    }

    protected function keyColumn(): string
    {
        return 'cla_periodo_pago';
    }

    protected function nameColumn(): string
    {
        return 'nom_periodo_pago';
    }
}
