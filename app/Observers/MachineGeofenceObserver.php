<?php

namespace App\Observers;

use App\Enums\Vending\GeofenceStatus;
use App\Models\MachineGeofence;
use App\Services\Audit\AuditLogger;

class MachineGeofenceObserver
{
    private const AUDITED = ['uuid', 'vending_machine_id', 'version', 'shape', 'center_latitude', 'center_longitude', 'radius_m', 'minimum_acceptable_accuracy_m', 'tolerance_m', 'valid_from', 'valid_until', 'status', 'source', 'created_by'];

    public function created(MachineGeofence $geofence): void
    {
        AuditLogger::log('geofence.created', $geofence, 'Machine geofence created.', [
            'after' => array_intersect_key($geofence->getAttributes(), array_flip(self::AUDITED)),
        ]);
    }

    public function updated(MachineGeofence $geofence): void
    {
        $changes = array_intersect(array_keys($geofence->getChanges()), self::AUDITED);
        if ($changes === []) {
            return;
        }

        $event = match ($geofence->getAttributes()['status'] ?? null) {
            GeofenceStatus::ACTIVE->value => 'geofence.activated',
            GeofenceStatus::SUPERSEDED->value => 'geofence.superseded',
            GeofenceStatus::INACTIVE->value => 'geofence.deactivated',
            default => 'geofence.updated',
        };

        AuditLogger::log($event, $geofence, 'Machine geofence updated.', [
            'before' => collect($changes)->mapWithKeys(fn ($key) => [$key => $geofence->getRawOriginal($key)])->all(),
            'after' => collect($changes)->mapWithKeys(fn ($key) => [$key => $geofence->getAttributes()[$key] ?? null])->all(),
        ]);
    }
}
