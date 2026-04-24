<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            UserAndEmpleadoSeeder::class,
            LocationSeeder::class,
            ClockSeeder::class,
            DeviceSeeder::class,
            ClockLogSeeder::class,
            FortiaMockEmployeeSeeder::class,
            EmployeeDataSeeder::class,
            CatalogsSeeder::class,
            EmployeeDetailsSeeder::class,
            RolePermissionSeeder::class,
            EnsureAdminAccessSeeder::class,
        ]);
    }
}
