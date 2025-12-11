<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        \App\Models\Company::create([
            'name' => 'Empresa Demo',
            'code' => 'DEMO',
            'status' => 1,
        ]);
    }
}

