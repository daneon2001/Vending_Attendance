<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        if (! $company) {
            return;
        }

        $units = [
            [
                'code' => 'MX-CMX-HQ',
                'name' => 'Matriz Ciudad de México',
                'description' => 'Oficinas corporativas y RH',
                'city' => 'Ciudad de México',
                'state' => 'CDMX',
                'country' => 'México',
                'address' => 'Av. Reforma 100, Cuauhtémoc, CDMX',
                'timezone' => 'America/Mexico_City',
                'status' => 1,
            ],
            [
                'code' => 'MX-NTE-OPS',
                'name' => 'Operaciones Norte',
                'description' => 'Centro de distribución Apodaca',
                'city' => 'Apodaca',
                'state' => 'Nuevo León',
                'country' => 'México',
                'address' => 'Parque Industrial FINSA, Apodaca',
                'timezone' => 'America/Monterrey',
                'status' => 1,
            ],
            [
                'code' => 'MX-BAJ-CLN',
                'name' => 'Clínica Bajío',
                'description' => 'Unidad médica León',
                'city' => 'León',
                'state' => 'Guanajuato',
                'country' => 'México',
                'address' => 'Blvd. Aeropuerto 1200, León',
                'timezone' => 'America/Mexico_City',
                'status' => 1,
            ],
            [
                'code' => 'MX-OCC-OPS',
                'name' => 'Operaciones Occidente',
                'description' => 'Centro logístico Guadalajara',
                'city' => 'Guadalajara',
                'state' => 'Jalisco',
                'country' => 'México',
                'address' => 'Periférico Sur 8500, Tlajomulco',
                'timezone' => 'America/Mexico_City',
                'status' => 0,
            ],
        ];

        foreach ($units as $unit) {
            Location::updateOrCreate(
                ['code' => $unit['code']],
                array_merge($unit, ['company_id' => $company->id]),
            );
        }
    }
}
