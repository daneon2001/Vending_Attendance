<?php

namespace Database\Seeders;

use App\Models\Clock;
use App\Models\Company;
use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ClockSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        $locations = Location::where('company_id', $company?->id)->get();

        if (! $company || $locations->isEmpty()) {
            return;
        }

        Clock::updateOrCreate(
            ['serial_number' => 'FT-CHK-001'],
            [
                'company_id' => $company->id,
                'clock_name' => 'Reloj Recepción Matriz',
                'ip_address' => '192.168.10.15',
                'type_inout' => 'INOUT',
                'status' => 1,
                'location_id' => $locations->where('code', 'MX-CMX-HQ')->first()?->id ?? $locations->first()->id,
                'firmware_version' => 'v5.2.1',
                'last_heartbeat_at' => Carbon::now()->subMinutes(1),
                'last_status_message' => 'Operando normalmente',
                'monitoring_status' => 'online',
                'program_status' => 'online',
            ],
        );

        Clock::updateOrCreate(
            ['serial_number' => 'FT-CHK-002'],
            [
                'company_id' => $company->id,
                'clock_name' => 'Reloj Patio Norte',
                'ip_address' => '10.0.5.77',
                'type_inout' => 'INOUT',
                'status' => 1,
                'location_id' => $locations->where('code', 'MX-NTE-OPS')->first()?->id ?? $locations->first()->id,
                'firmware_version' => 'v5.2.1',
                'last_heartbeat_at' => Carbon::now()->subMinutes(12),
                'last_status_message' => 'Sincronizando con Fortia',
                'monitoring_status' => 'warning',
                'program_status' => 'standby',
            ],
        );

        Clock::updateOrCreate(
            ['serial_number' => 'FT-CHK-003'],
            [
                'company_id' => $company->id,
                'clock_name' => 'Reloj Clínica Bajío',
                'ip_address' => '10.45.8.12',
                'type_inout' => 'INOUT',
                'status' => 0,
                'location_id' => $locations->where('code', 'MX-BAJ-CLN')->first()?->id ?? $locations->first()->id,
                'firmware_version' => 'v4.9.8',
                'last_heartbeat_at' => Carbon::now()->subMinutes(60),
                'last_status_message' => 'Sin conexión al middleware',
                'monitoring_status' => 'offline',
                'program_status' => 'offline',
            ],
        );
    }
}
