<?php

namespace Database\Seeders;

use App\Models\Clock;
use App\Models\Device;
use Illuminate\Database\Seeder;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        $clock = Clock::query()
            ->orderBy('id')
            ->first();

        if (! $clock) {
            return;
        }

        Device::updateOrCreate(
            ['device_serial' => 'CH-XOCH-001'],
            [
                'clock_id' => $clock->id,
                'unit_id' => $clock->location_id,
                'company_id' => $clock->company_id,
                'shared_secret' => 'onprem_dev_secret_001',
                'is_active' => true,
                'last_seen_at' => null,
                'last_heartbeat_at' => null,
                'last_status' => null,
            ],
        );

        Device::updateOrCreate(
            ['device_serial' => (string) ($clock->serial_number ?: 'FT-CHK-001')],
            [
                'clock_id' => $clock->id,
                'unit_id' => $clock->location_id,
                'company_id' => $clock->company_id,
                'shared_secret' => 'onprem_dev_secret_clock_001',
                'is_active' => true,
                'last_seen_at' => null,
                'last_heartbeat_at' => null,
                'last_status' => null,
            ],
        );
    }
}
