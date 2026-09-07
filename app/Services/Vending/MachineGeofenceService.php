<?php

namespace App\Services\Vending;

use App\Enums\Vending\GeofenceShape;
use App\Enums\Vending\GeofenceStatus;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MachineGeofenceService
{
    public function __construct(private readonly MachineConfigurationVersionService $configurationVersions) {}

    public function create(VendingMachine $machine, array $attributes, ?int $actorId = null, ?int $expectedVersion = null): MachineGeofence
    {
        // Nullable input means the same zero margin used by the existing validator.
        $attributes['tolerance_m'] ??= 0;
        $activate = ($attributes['status'] ?? GeofenceStatus::DRAFT->value) === GeofenceStatus::ACTIVE->value;

        return DB::transaction(function () use ($machine, $attributes, $actorId, $expectedVersion, $activate): MachineGeofence {
            $locked = VendingMachine::query()->whereKey($machine->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, $expectedVersion);
            $version = ((int) MachineGeofence::query()
                ->where('vending_machine_id', $machine->getKey())
                ->max('version')) + 1;

            $geofence = $machine->geofences()->create(array_merge($attributes, [
                'version' => $version,
                'shape' => GeofenceShape::CIRCLE->value,
                'status' => GeofenceStatus::DRAFT->value,
                'created_by' => $actorId,
            ]));

            return $activate ? $this->activate($geofence, $actorId) : $geofence;
        }, 3);
    }

    public function activate(MachineGeofence $geofence, ?int $actorId = null, ?int $expectedVersion = null): MachineGeofence
    {
        return DB::transaction(function () use ($geofence, $actorId, $expectedVersion): MachineGeofence {
            $machine = VendingMachine::query()->whereKey($geofence->vending_machine_id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($machine, $expectedVersion);
            $candidate = MachineGeofence::query()->whereKey($geofence->getKey())->lockForUpdate()->firstOrFail();
            $activatedAt = now();

            if ($candidate->status === GeofenceStatus::ACTIVE) {
                return $candidate;
            }
            if ($candidate->status === GeofenceStatus::SUPERSEDED) {
                throw ValidationException::withMessages([
                    'geofence' => 'Esta geocerca fue reemplazada. Crea una nueva versión para utilizarla.',
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

    public function deactivate(MachineGeofence $geofence, ?int $expectedVersion = null): MachineGeofence
    {
        return DB::transaction(function () use ($geofence, $expectedVersion): MachineGeofence {
            $machine = VendingMachine::query()->whereKey($geofence->vending_machine_id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($machine, $expectedVersion);
            $candidate = MachineGeofence::query()->whereKey($geofence->getKey())->lockForUpdate()->firstOrFail();
            if ($candidate->status === GeofenceStatus::INACTIVE) {
                return $candidate;
            }
            if ($candidate->status !== GeofenceStatus::ACTIVE) {
                throw ValidationException::withMessages(['geofence' => 'Sólo se puede desactivar la geocerca activa.']);
            }
            $candidate->forceFill(['status' => GeofenceStatus::INACTIVE, 'valid_until' => now()])->save();
            $this->configurationVersions->bump($machine, 'geofence.deactivated');

            return $candidate->fresh();
        }, 3);
    }

    public function verifyLocation(VendingMachine $machine, int $expectedVersion): void
    {
        DB::transaction(function () use ($machine, $expectedVersion): void {
            $locked = VendingMachine::query()->whereKey($machine->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, $expectedVersion);
            try {
                if ($locked->latitude === null || $locked->longitude === null) {
                    throw new \InvalidArgumentException;
                }
                app(GeofenceValidationService::class)->distanceInMeters((float) $locked->latitude, (float) $locked->longitude, (float) $locked->latitude, (float) $locked->longitude);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['location' => 'La máquina necesita una ubicación registrada válida antes de verificarla.']);
            }
            $before = $locked->only(['coordinates_verified', 'coordinates_verified_at']);
            $locked->update(['coordinates_verified' => true, 'coordinates_verified_at' => now()]);
            AuditLogger::log('vending_machine.location_verified', $locked, 'Ubicación registrada verificada por el operador.', [
                'before' => $before,
                'after' => $locked->only(['coordinates_verified', 'coordinates_verified_at']),
            ]);
        }, 3);
    }

    private function assertVersion(VendingMachine $machine, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && (int) $machine->config_version !== $expectedVersion) {
            throw ValidationException::withMessages(['expected_config_version' => 'La configuración cambió mientras editabas. Recarga la página y revisa los cambios antes de guardar.']);
        }
    }
}
