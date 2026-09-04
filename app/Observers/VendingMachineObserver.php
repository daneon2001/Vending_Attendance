<?php

namespace App\Observers;

use App\Enums\Vending\VendingMachineStatus;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;

class VendingMachineObserver
{
    private const AUDITED = ['uuid', 'sybi_id', 'machine_code', 'operational_code', 'name', 'address_line', 'neighborhood', 'locality', 'municipality', 'state', 'postal_code', 'country', 'latitude', 'longitude', 'coordinate_source', 'coordinates_verified', 'coordinates_verified_at', 'timezone', 'status', 'default_geofence_radius_m', 'installed_at', 'retired_at', 'config_version'];

    public function created(VendingMachine $machine): void
    {
        AuditLogger::log('vending_machine.created', $machine, 'Vending machine created.', [
            'after' => $this->safeAttributes($machine),
        ]);
    }

    public function updated(VendingMachine $machine): void
    {
        $changes = array_intersect(array_keys($machine->getChanges()), self::AUDITED);
        if ($changes === []) {
            return;
        }

        $currentStatus = $machine->getAttributes()['status'] ?? null;
        $event = match ($machine->getRawOriginal('status') !== $currentStatus ? $currentStatus : null) {
            VendingMachineStatus::ACTIVE->value => 'vending_machine.activated',
            VendingMachineStatus::INACTIVE->value => 'vending_machine.deactivated',
            VendingMachineStatus::RETIRED->value => 'vending_machine.retired',
            default => 'vending_machine.updated',
        };

        AuditLogger::log($event, $machine, 'Vending machine updated.', [
            'before' => $this->values($machine, $changes, true),
            'after' => $this->values($machine, $changes, false),
        ]);
    }

    private function safeAttributes(VendingMachine $machine): array
    {
        return array_intersect_key($machine->getAttributes(), array_flip(self::AUDITED));
    }

    private function values(VendingMachine $machine, array $keys, bool $original): array
    {
        return collect($keys)->mapWithKeys(fn (string $key) => [
            $key => $original ? $machine->getRawOriginal($key) : ($machine->getAttributes()[$key] ?? null),
        ])->all();
    }
}
