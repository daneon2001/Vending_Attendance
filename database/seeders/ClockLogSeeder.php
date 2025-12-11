<?php

namespace Database\Seeders;

use App\Models\Clock;
use App\Models\ClockLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ClockLogSeeder extends Seeder
{
    public function run(): void
    {
        $clocks = Clock::all();

        if ($clocks->isEmpty()) {
            return;
        }

        foreach ($clocks as $clock) {
            ClockLog::updateOrCreate(
                [
                    'clock_id' => $clock->id,
                    'event_type' => 'heartbeat',
                    'occurred_at' => Carbon::now()->subMinutes(5),
                ],
                [
                    'level' => 'info',
                    'source' => 'monitor',
                    'message' => 'Pulso recibido correctamente desde '.$clock->clock_name,
                    'payload' => ['ip' => $clock->ip_address],
                ]
            );

            ClockLog::updateOrCreate(
                [
                    'clock_id' => $clock->id,
                    'event_type' => 'sync',
                    'occurred_at' => Carbon::now()->subMinutes(30),
                ],
                [
                    'level' => 'warning',
                    'source' => 'sync-daemon',
                    'message' => 'Sincronización pendiente por latencia en red',
                    'payload' => ['latency_ms' => random_int(1200, 3600)],
                ]
            );
        }
    }
}
