<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        \App\Models\Company::updateOrCreate(
            ['code' => 'DEMO'],
            [
                'name' => 'Empresa Demo',
                'status' => 1,
            ]
        );
    }
}
