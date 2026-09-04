<?php

namespace App\Services\Vending;

use App\Models\VendingMachine;
use Illuminate\Support\Facades\DB;

class MachineConfigurationVersionService
{
    public function bump(VendingMachine $machine, string $reason): int
    {
        return DB::transaction(function () use ($machine): int {
            $locked = VendingMachine::query()->whereKey($machine->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['config_version' => ((int) $locked->config_version) + 1])->save();

            return (int) $locked->config_version;
        });
    }

    public function hasChanged(VendingMachine $machine, ?int $appliedVersion): bool
    {
        return $appliedVersion === null || $appliedVersion !== (int) $machine->config_version;
    }
}
