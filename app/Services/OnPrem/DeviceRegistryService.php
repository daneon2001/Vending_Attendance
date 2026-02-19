<?php

namespace App\Services\OnPrem;

use App\Models\Clock;
use App\Models\Device;

class DeviceRegistryService
{
    public function syncFromClock(Clock $clock, ?string $sharedSecret = null): Device
    {
        $serial = trim((string) $clock->serial_number);
        if ($serial === '') {
            throw new \InvalidArgumentException('Clock serial_number is required to sync devices table.');
        }

        $existingDevice = Device::query()
            ->where('device_serial', $serial)
            ->first();

        $secret = $this->resolveSharedSecret($existingDevice, $sharedSecret);

        $payload = [
            'clock_id' => $clock->id,
            'unit_id' => $clock->location_id,
            'company_id' => $clock->company_id,
            'is_active' => (int) ($clock->status ?? 1) === 1,
        ];

        if ($secret !== '') {
            $payload['shared_secret'] = $secret;
        }

        return Device::query()->updateOrCreate(
            ['device_serial' => $serial],
            $payload,
        );
    }

    private function resolveSharedSecret(?Device $device, ?string $incomingSecret): string
    {
        $incoming = trim((string) ($incomingSecret ?? ''));
        if ($incoming !== '') {
            return $incoming;
        }

        $existing = trim((string) ($device?->shared_secret ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        // Permite backfill controlado sin romper despliegues donde aun no llega enroller-app.
        return trim((string) config('onprem.default_shared_secret', ''));
    }
}
