<?php

namespace App\Services\Vending;

use App\Enums\Vending\GeofenceShape;
use App\Enums\Vending\GeofenceStatus;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MachineGeofenceService
{
    public function __construct(private readonly MachineConfigurationVersionService $configurationVersions) {}

    public function create(VendingMachine $machine, array $attributes, ?int $actorId = null): MachineGeofence
    {
        $activate = ($attributes['status'] ?? GeofenceStatus::DRAFT->value) === GeofenceStatus::ACTIVE->value;

        $geofence = DB::transaction(function () use ($machine, $attributes, $actorId): MachineGeofence {
            VendingMachine::query()->whereKey($machine->getKey())->lockForUpdate()->firstOrFail();
            $version = ((int) MachineGeofence::query()
                ->where('vending_machine_id', $machine->getKey())
                ->max('version')) + 1;

            return $machine->geofences()->create(array_merge($attributes, [
                'version' => $version,
                'shape' => GeofenceShape::CIRCLE->value,
                'status' => GeofenceStatus::DRAFT->value,
                'created_by' => $actorId,
            ]));
        });

        return $activate ? $this->activate($geofence, $actorId) : $geofence;
    }

    public function activate(MachineGeofence $geofence, ?int $actorId = null): MachineGeofence
    {
        return DB::transaction(function () use ($geofence, $actorId): MachineGeofence {
            $candidate = MachineGeofence::query()->whereKey($geofence->getKey())->lockForUpdate()->firstOrFail();
            $machine = VendingMachine::query()->whereKey($candidate->vending_machine_id)->lockForUpdate()->firstOrFail();
            $activatedAt = now();

            if ($candidate->status === GeofenceStatus::ACTIVE) {
                return $candidate;
            }
            if ($candidate->status === GeofenceStatus::SUPERSEDED) {
                throw ValidationException::withMessages([
                    'geofence' => 'A superseded geofence cannot be reactivated; create a new version.',
                ]);
            }

            MachineGeofence::query()
                ->where('vending_machine_id', $machine->getKey())
                ->whereKeyNot($candidate->getKey())
                ->active()
                ->lockForUpdate()
                ->get()
                ->each(function (MachineGeofence $active) use ($activatedAt): void {
                    $active->forceFill([
                        'status' => GeofenceStatus::SUPERSEDED,
                        'valid_until' => $activatedAt,
                    ])->save();
                });

            $candidate->forceFill([
                'status' => GeofenceStatus::ACTIVE,
                'valid_from' => $candidate->valid_from ?? $activatedAt,
                'valid_until' => null,
                'created_by' => $candidate->created_by ?? $actorId,
            ])->save();

            $this->configurationVersions->bump($machine, 'geofence.activated');

            return $candidate->fresh();
        }, 3);
    }
}
