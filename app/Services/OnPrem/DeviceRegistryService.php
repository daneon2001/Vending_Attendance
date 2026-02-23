<?php

namespace App\Services\OnPrem;

use App\Models\Clock;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DeviceRegistryService
{
    public function syncFromClock(
        Clock $clock,
        ?string $deviceSerial = null,
        ?string $sharedSecret = null,
    ): ?Device
    {
        if (! Schema::hasTable('devices')) {
            Log::warning('onprem.device_registry.sync.skipped_missing_table', [
                'clock_id' => $clock->id,
            ]);

            return null;
        }

        $serial = $this->resolveDeviceSerial($clock, $deviceSerial);
        if ($serial === null) {
            Log::warning('onprem.device_registry.sync.skipped_missing_serial', [
                'clock_id' => $clock->id,
                'input_device_serial' => $deviceSerial,
                'clock_fields' => [
                    'device_serial' => $clock->getAttribute('device_serial'),
                    'serial' => $clock->getAttribute('serial'),
                    'serial_number' => $clock->getAttribute('serial_number'),
                    'device_id' => $clock->getAttribute('device_id'),
                    'st_Serial' => $clock->getAttribute('st_Serial'),
                ],
            ]);

            return null;
        }

        $existingDevice = Device::query()
            ->where('device_serial', $serial)
            ->first();

        $secret = $this->resolveSharedSecret($existingDevice, $sharedSecret);

        $payload = [];
        if ($this->hasDeviceColumn('clock_id')) {
            $payload['clock_id'] = $clock->id;
        }
        if ($this->hasDeviceColumn('unit_id')) {
            $payload['unit_id'] = $clock->location_id;
        }
        if ($this->hasDeviceColumn('company_id')) {
            $payload['company_id'] = $clock->company_id;
        }
        if ($this->hasDeviceColumn('is_active')) {
            $payload['is_active'] = (int) ($clock->status ?? 1) === 1;
        }
        if ($this->hasDeviceColumn('last_seen_at')) {
            $payload['last_seen_at'] = now();
        }

        if ($secret !== '') {
            $payload['shared_secret'] = $secret;
        }

        return Device::query()->updateOrCreate(
            ['device_serial' => $serial],
            $payload,
        );
    }

    private function resolveDeviceSerial(Clock $clock, ?string $deviceSerial): ?string
    {
        $candidates = [
            $deviceSerial,
            $clock->getAttribute('device_serial'),
            $clock->getAttribute('serial'),
            $clock->getAttribute('serial_number'),
            $clock->getAttribute('device_id'),
            $clock->getAttribute('st_Serial'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim((string) ($candidate ?? ''));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function hasDeviceColumn(string $column): bool
    {
        static $cache = [];

        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $cache[$column] = Schema::hasColumn('devices', $column);

        return $cache[$column];
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
