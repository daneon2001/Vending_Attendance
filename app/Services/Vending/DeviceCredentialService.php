<?php

namespace App\Services\Vending;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class DeviceCredentialService
{
    /**
     * @return array{credential:string,version:int}
     */
    public function issue(Device $device): array
    {
        return $this->persistNewCredential($device);
    }

    /**
     * @return array{credential:string,version:int}
     */
    public function rotate(Device $device): array
    {
        return DB::transaction(function () use ($device): array {
            $locked = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $issued = $this->persistNewCredential($locked);

            AuditLogger::log('device.credential.rotated', $locked, 'Device credential rotated.', [
                'after' => ['credential_version' => $issued['version']],
            ]);

            return $issued;
        });
    }

    public function revoke(Device $device): void
    {
        DB::transaction(function () use ($device): void {
            $locked = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'credential_secret' => null,
                'credential_revoked_at' => now(),
                'status' => DeviceStatus::REVOKED,
                'is_active' => false,
            ])->save();
        });
    }

    private function persistNewCredential(Device $device): array
    {
        $plainCredential = bin2hex(random_bytes(32));
        $version = ((int) $device->credential_version) + 1;

        $device->forceFill([
            'credential_secret' => $plainCredential,
            'credential_version' => $version,
            'credential_issued_at' => now(),
            'credential_revoked_at' => null,
        ])->save();

        return ['credential' => $plainCredential, 'version' => $version];
    }
}
