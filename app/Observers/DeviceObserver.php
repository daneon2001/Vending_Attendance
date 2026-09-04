<?php

namespace App\Observers;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Services\Audit\AuditLogger;

class DeviceObserver
{
    public function updated(Device $device): void
    {
        if ($device->vending_machine_id === null || ! $device->wasChanged('status')) {
            return;
        }

        $event = match ($device->status) {
            DeviceStatus::ACTIVE => 'device.activated',
            DeviceStatus::SUSPENDED => 'device.suspended',
            DeviceStatus::REVOKED => 'device.revoked',
            DeviceStatus::RETIRED => 'device.retired',
            default => null,
        };

        if ($event) {
            AuditLogger::log($event, $device, 'Device lifecycle status changed.', [
                'before' => ['status' => $device->getRawOriginal('status')],
                'after' => ['status' => $device->status->value],
            ]);
        }
    }
}
