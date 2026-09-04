<?php

namespace App\Services\Vending;

use App\Enums\Vending\CoordinateSource;
use App\Enums\Vending\SybiVendingSyncStatus;
use App\Enums\Vending\VendingCatalogSource;
use App\Enums\Vending\VendingMachineStatus;
use App\Integrations\Sybi\SybiVendingSourceCandidate;
use App\Models\SybiVendingSourceRecord;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;

class SybiVendingPromotionService
{
    private const OWNED_ATTRIBUTES = [
        'sybi_id', 'machine_code', 'name', 'address_line', 'neighborhood',
        'postal_code', 'sybi_city_id', 'sybi_state_id', 'sybi_full_address',
        'latitude', 'longitude',
    ];

    public function hasIdentityCollision(SybiVendingSourceCandidate $candidate): bool
    {
        if ($candidate->vendingIdentifier === null) {
            return false;
        }

        $bySybi = VendingMachine::query()->where('sybi_id', $candidate->sybiId)->first();
        $byCode = VendingMachine::query()->where('machine_code', $candidate->vendingIdentifier)->first();

        return ($bySybi && $byCode && ! $bySybi->is($byCode))
            || (! $bySybi && $byCode);
    }

    public function promote(
        SybiVendingSourceCandidate $candidate,
        bool $dryRun,
        ?SybiVendingSourceRecord $sourceRecord = null,
    ): SybiVendingPromotionResult {
        if (! $candidate->isReady()) {
            return new SybiVendingPromotionResult('SKIPPED');
        }

        $data = $candidate->machineAttributes();
        $bySybi = VendingMachine::query()->where('sybi_id', $candidate->sybiId)->first();
        $byCode = VendingMachine::query()->where('machine_code', $candidate->vendingIdentifier)->first();

        if (($bySybi && $byCode && ! $bySybi->is($byCode)) || (! $bySybi && $byCode)) {
            return new SybiVendingPromotionResult('CONFLICT', reason: 'MACHINE_CODE_ALREADY_OWNED');
        }

        if (! $bySybi) {
            if ($dryRun) {
                return new SybiVendingPromotionResult('CREATED');
            }

            $machine = VendingMachine::query()->create(array_merge($data, [
                'source' => VendingCatalogSource::SYBI,
                'coordinate_source' => CoordinateSource::SYBI,
                'status' => VendingMachineStatus::DRAFT,
                'coordinates_verified' => false,
                'sybi_last_seen_at' => now()->utc(),
                'sybi_sync_status' => SybiVendingSyncStatus::SYNCED,
                'geofence_review_required' => false,
            ]));
            $this->link($sourceRecord, $machine);
            AuditLogger::log('vending_machine.sybi_created', $machine, 'Vending machine promoted from the SYBIML source projection.', [
                'after' => $this->safeAttributes($machine),
            ]);

            return new SybiVendingPromotionResult('CREATED', $machine);
        }

        $ownedChanges = $this->changedOwnedAttributes($bySybi, $data);
        $trackingChanged = $bySybi->source !== VendingCatalogSource::SYBI
            || $bySybi->sybi_sync_status === SybiVendingSyncStatus::SOURCE_MISSING;
        $action = $ownedChanges !== [] || $trackingChanged ? 'UPDATED' : 'UNCHANGED';

        if ($dryRun) {
            return new SybiVendingPromotionResult($action, $bySybi);
        }

        $coordinateChanged = $this->coordinatesChanged($bySybi, $data);
        $beforeCoordinates = ['latitude' => $bySybi->latitude, 'longitude' => $bySybi->longitude];
        $hasActiveGeofence = $coordinateChanged && $bySybi->activeGeofence()->exists();
        $attributes = array_merge($data, [
            'source' => VendingCatalogSource::SYBI,
            'coordinate_source' => CoordinateSource::SYBI,
            'sybi_last_seen_at' => now()->utc(),
            'sybi_sync_status' => $hasActiveGeofence || $bySybi->geofence_review_required
                ? SybiVendingSyncStatus::REVIEW_REQUIRED
                : SybiVendingSyncStatus::SYNCED,
        ]);

        if ($coordinateChanged) {
            $attributes['coordinates_verified'] = false;
            $attributes['sybi_coordinates_changed_at'] = now()->utc();
        }
        if ($hasActiveGeofence) {
            $attributes['geofence_review_required'] = true;
        }

        $bySybi->fill($attributes)->save();
        $this->link($sourceRecord, $bySybi);

        if ($action === 'UPDATED') {
            AuditLogger::log('vending_machine.sybi_updated', $bySybi, 'Vending machine updated from the SYBIML source projection.', [
                'after' => $this->safeAttributes($bySybi),
            ]);
        }
        if ($coordinateChanged) {
            AuditLogger::log('vending_machine.coordinates_changed', $bySybi, 'SYBIML reported new machine coordinates.', [
                'before' => $beforeCoordinates,
                'after' => ['latitude' => $bySybi->latitude, 'longitude' => $bySybi->longitude],
            ]);
        }
        if ($hasActiveGeofence) {
            AuditLogger::log('geofence.review_required', $bySybi, 'Active geofence requires review after a SYBIML coordinate change.');
        }

        return new SybiVendingPromotionResult($action, $bySybi);
    }

    private function link(?SybiVendingSourceRecord $sourceRecord, VendingMachine $machine): void
    {
        if ($sourceRecord && $sourceRecord->promoted_vending_machine_id !== $machine->getKey()) {
            $sourceRecord->forceFill(['promoted_vending_machine_id' => $machine->getKey()])->save();
        }
    }

    private function coordinatesChanged(VendingMachine $machine, array $data): bool
    {
        return $this->decimalChanged($machine->latitude, $data['latitude'])
            || $this->decimalChanged($machine->longitude, $data['longitude']);
    }

    private function decimalChanged(mixed $current, mixed $incoming): bool
    {
        if ($current === null || $incoming === null) {
            return $current !== $incoming;
        }

        return abs((float) $current - (float) $incoming) > 0.00000005;
    }

    private function changedOwnedAttributes(VendingMachine $machine, array $data): array
    {
        return collect(self::OWNED_ATTRIBUTES)
            ->filter(function (string $attribute) use ($machine, $data): bool {
                if (in_array($attribute, ['latitude', 'longitude'], true)) {
                    return $this->decimalChanged($machine->getAttribute($attribute), $data[$attribute]);
                }

                return (string) ($machine->getAttribute($attribute) ?? '') !== (string) ($data[$attribute] ?? '');
            })
            ->values()
            ->all();
    }

    private function safeAttributes(VendingMachine $machine): array
    {
        return $machine->only(array_merge(self::OWNED_ATTRIBUTES, ['source', 'coordinate_source']));
    }
}
