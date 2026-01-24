<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use App\Models\Empleado;

class UserAndEmpleadoSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Usuario admin
        $user = User::firstOrCreate(
            ['email' => 'admin@gmail.com'], // clave única
            [
                'name'    => 'Admin Asistencias',
                'password'=> bcrypt('password'), // cámbialo a lo que quieras
                'estatus' => 1,
            ]
        );

        // 2) Empresa (asumiendo que CompanySeeder ya creó una)
        $company = Company::first(); // o Company::firstOrCreate([...])

        // 3) Empleado ligado al admin
        Empleado::firstOrCreate(
            [
                'user_id' => $user->id,
            ],
            [
                'company_id'    => $company?->id,
                'num_empleado'  => 'A001',
                'employee_code' => '1001',
                'nombre'        => 'Admin',
                'apellidos'     => 'Asistencias',
                'status'        => 1,
            ]
        );
    }
}
