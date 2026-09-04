<?php

namespace App\Services\Vending;

use App\Models\Device;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class MachineConfigurationManifestService
{
    public function __construct(private readonly ManifestHashService $hashes) {}

    public function snapshot(Device $device, DateTimeInterface|string|null $timestamp = null): array
    {
        $generatedAt = $timestamp instanceof DateTimeInterface
            ? Carbon::instance($timestamp)
            : Carbon::parse($timestamp ?? 'now');
        $machine = $device->vendingMachine()->firstOrFail();
        $geofence = $machine->activeGeofence()->first();

        $content = [
            'manifest_type' => 'MACHINE_CONFIGURATION',
            'manifest_version' => (int) $machine->config_version,
            'device' => ['uuid' => $device->uuid],
            'machine' => [
                'uuid' => $machine->uuid,
                'machine_code' => $machine->machine_code,
                'status' => $machine->status->value,
                'timezone' => $machine->timezone,
            ],
            'geofence' => $geofence ? [
                'uuid' => $geofence->uuid,
                'version' => (int) $geofence->version,
                'type' => $geofence->shape->value,
                'latitude' => (float) $geofence->center_latitude,
                'longitude' => (float) $geofence->center_longitude,
                'radius_m' => (int) $geofence->radius_m,
                'minimum_acceptable_accuracy_m' => $geofence->minimum_acceptable_accuracy_m !== null
                    ? (float) $geofence->minimum_acceptable_accuracy_m
                    : null,
                'tolerance_m' => (float) $geofence->tolerance_m,
            ] : null,
        ];

        return array_merge($content, [
            'manifest_hash' => $this->hashes->hash($content),
            'generated_at' => $generatedAt->copy()->utc()->toIso8601String(),
            'server_time' => $generatedAt->copy()->utc()->toIso8601String(),
        ]);
    }
}
