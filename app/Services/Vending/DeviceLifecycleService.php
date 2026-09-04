<?php

namespace App\Services\Vending;

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceLifecycleService
{
    public function transition(Device $device, DeviceStatus $target): Device
    {
        return DB::transaction(function () use ($device, $target): Device {
            $locked = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->vending_machine_id === null) {
                throw ValidationException::withMessages(['device' => 'Only vending devices use this lifecycle.']);
            }

            $allowed = match ($locked->status) {
                DeviceStatus::PENDING => [DeviceStatus::ACTIVE, DeviceStatus::REVOKED, DeviceStatus::RETIRED],
                DeviceStatus::ACTIVE => [DeviceStatus::SUSPENDED, DeviceStatus::REVOKED, DeviceStatus::RETIRED],
                DeviceStatus::SUSPENDED => [DeviceStatus::ACTIVE, DeviceStatus::REVOKED, DeviceStatus::RETIRED],
                DeviceStatus::REVOKED, DeviceStatus::RETIRED => [],
            };

            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'Requested device lifecycle transition is not allowed.']);
            }
            if ($target === DeviceStatus::ACTIVE && blank($locked->credential_secret)) {
                throw ValidationException::withMessages(['credential' => 'An active device requires a credential.']);
            }

            $attributes = ['status' => $target, 'is_active' => $target === DeviceStatus::ACTIVE];
            if (in_array($target, [DeviceStatus::REVOKED, DeviceStatus::RETIRED], true)) {
                $attributes['credential_secret'] = null;
                $attributes['credential_revoked_at'] = now();
            }
            if ($target === DeviceStatus::RETIRED) {
                $attributes['retired_at'] = now();
            }

            $locked->forceFill($attributes)->save();

            return $locked->fresh();
        });
    }
}
