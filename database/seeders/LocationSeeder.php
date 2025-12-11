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

        Location::firstOrCreate(
            ['code' => 'MX-CMX-HQ'],
            [
                'company_id' => $company->id,
                'name' => 'Matriz Ciudad de México',
                'timezone' => 'America/Mexico_City',
                'status' => 1,
                'address' => 'Av. Reforma 100, Cuauhtémoc, CDMX',
            ],
        );

        Location::firstOrCreate(
            ['code' => 'MX-NTE-OPS'],
            [
                'company_id' => $company->id,
                'name' => 'Operaciones Norte',
                'timezone' => 'America/Monterrey',
                'status' => 1,
                'address' => 'Parque Industrial Apodaca, NL',
            ],
        );
    }
}
