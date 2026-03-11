<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Empleado;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserAndEmpleadoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin Asistencias',
                'password' => Hash::make('password'),
                'estatus' => 1,
                'email_verified_at' => now(),
            ]
        );

        $company = Company::first();

        $payload = [
            'user_id' => $user->id,
            'company_id' => $company?->id,
            'num_empleado' => 'A001',
            'employee_code' => '1001',
            'nombre' => 'Admin',
            'apellidos' => 'Asistencias',
            'status' => 1,
        ];

        $empleado = Empleado::query()
            ->where('user_id', $user->id)
            ->orWhere(function ($query) use ($company): void {
                $query->where('company_id', $company?->id)
                    ->where('num_empleado', 'A001');
            })
            ->orWhere(function ($query) use ($company): void {
                $query->where('company_id', $company?->id)
                    ->where('employee_code', '1001');
            })
            ->first();

        if ($empleado) {
            $empleado->fill($payload)->save();

            return;
        }

        Empleado::create($payload);
    }
}
