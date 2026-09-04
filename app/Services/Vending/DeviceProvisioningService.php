<?php

namespace App\Services\Vending;

use App\Enums\DeviceStatus;
use App\Enums\Vending\ManifestType;
use App\Exceptions\DeviceProvisioningException;
use App\Models\Device;
use App\Models\DeviceProvisioningToken;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeviceProvisioningService
{
    public function __construct(private readonly DeviceCredentialService $credentials) {}

    /**
     * @return array{device:Device,credential:string,credential_version:int}
     */
    public function provision(string $plainToken, array $attributes): array
    {
        return DB::transaction(function () use ($plainToken, $attributes): array {
            $token = DeviceProvisioningToken::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $token || ! $token->isUsable()) {
                throw new DeviceProvisioningException(
                    'INVALID_PROVISIONING_TOKEN',
                    'Provisioning token is invalid, expired, revoked, or already used.',
                );
            }

            $machine = VendingMachine::query()->whereKey($token->vending_machine_id)->lockForUpdate()->firstOrFail();
            $serial = trim((string) $attributes['device_serial']);
            $existing = Device::query()->where('device_serial', $serial)->lockForUpdate()->first();

            if ($existing) {
                $code = $existing->vending_machine_id !== null && $existing->vending_machine_id !== $machine->id
                    ? 'DEVICE_MACHINE_MISMATCH'
                    : 'DEVICE_SERIAL_ALREADY_REGISTERED';

                throw new DeviceProvisioningException($code, 'Device serial is already registered.', 409);
            }

            Device::query()
                ->where('vending_machine_id', $machine->id)
                ->where('status', DeviceStatus::ACTIVE->value)
                ->lockForUpdate()
                ->get()
                ->each(function (Device $active): void {
                    $active->forceFill([
                        'status' => DeviceStatus::RETIRED,
                        'is_active' => false,
                        'retired_at' => now(),
                        'credential_secret' => null,
                        'credential_revoked_at' => now(),
                    ])->save();
                });

            $device = Device::query()->create([
                'vending_machine_id' => $machine->id,
                'device_serial' => $serial,
                'device_name' => $attributes['device_name'] ?? null,
                'platform' => $attributes['platform'] ?? null,
                'platform_version' => $attributes['platform_version'] ?? null,
                'app_version' => $attributes['app_version'] ?? null,
                'hardware_model' => $attributes['hardware_model'] ?? null,
                'status' => DeviceStatus::PENDING,
                'shared_secret' => '',
                'is_active' => false,
            ]);

            $issued = $this->credentials->issue($device);
            $now = now();
            $device->forceFill([
                'status' => DeviceStatus::ACTIVE,
                'is_active' => true,
                'provisioned_at' => $now,
                'activated_at' => $now,
            ])->save();

            $token->forceFill([
                'used_at' => $now,
                'used_by_device_id' => $device->id,
            ])->save();

            if (Schema::hasTable('device_manifest_states')) {
                $device->manifestStates()->createMany(array_map(
                    fn (ManifestType $type): array => ['manifest_type' => $type->value],
                    ManifestType::cases(),
                ));
            }

            AuditLogger::log('device.provisioned', $device, 'Device provisioned for vending machine.', [
                'after' => [
                    'device_uuid' => $device->uuid,
                    'vending_machine_id' => $machine->id,
                    'status' => DeviceStatus::ACTIVE->value,
                    'credential_version' => $issued['version'],
                ],
            ]);

            return [
                'device' => $device->fresh(),
                'credential' => $issued['credential'],
                'credential_version' => $issued['version'],
            ];
        }, 3);
    }
}
