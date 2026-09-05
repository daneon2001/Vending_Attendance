<?php

namespace App\Services\Vending;

use App\Enums\Vending\ManifestAckStatus;
use App\Enums\Vending\ManifestSyncState;
use App\Enums\Vending\ManifestType;
use App\Models\Device;
use App\Models\DeviceManifestState;
use Illuminate\Support\Carbon;

class DeviceManifestStatusService
{
    public function __construct(
        private readonly MachineConfigurationManifestService $configurationManifests,
        private readonly EmployeeManifestService $employeeManifests,
    ) {}

    public function status(Device $device, ?Carbon $now = null): array
    {
        $now ??= now();
        $configuration = $this->configurationManifests->snapshot($device, $now);
        $employees = $this->employeeManifests->snapshot($device->vendingMachine()->firstOrFail(), $now);
        $states = $device->manifestStates()->get()->keyBy(
            fn (DeviceManifestState $state): string => $state->manifest_type->value,
        );
        $configurationStatus = $this->typeStatus(
            $device,
            $states->get(ManifestType::CONFIGURATION->value),
            ManifestType::CONFIGURATION,
            $configuration['manifest_version'],
            $configuration['manifest_hash'],
            $now,
        );
        $employeeStatus = $this->typeStatus(
            $device,
            $states->get(ManifestType::EMPLOYEES->value),
            ManifestType::EMPLOYEES,
            $employees['manifest_version'],
            $employees['manifest_hash'],
            $now,
        );
        $overall = $this->overallState([$configurationStatus['state'], $employeeStatus['state']]);

        return [
            'configuration' => $configurationStatus,
            'employees' => $employeeStatus,
            'biometrics' => [
                'server_version' => null,
                'applied_version' => null,
                'changed' => false,
                'supported' => false,
            ],
            'sync_state' => $overall->value,
            'server_time' => $now->copy()->utc()->toIso8601String(),
        ];
    }

    /**
     * Lightweight fleet projection. It uses persisted monotonic versions and
     * ACK state only; it deliberately does not build or hash full manifests.
     */
    public function summary(Device $device, ?Carbon $now = null): array
    {
        $now ??= now();
        $machine = $device->relationLoaded('vendingMachine')
            ? $device->vendingMachine
            : $device->vendingMachine()->firstOrFail();
        $states = ($device->relationLoaded('manifestStates')
            ? $device->manifestStates
            : $device->manifestStates()->get())->keyBy(
                fn (DeviceManifestState $state): string => $state->manifest_type->value,
            );
        $configuration = $this->typeStatus(
            $device,
            $states->get(ManifestType::CONFIGURATION->value),
            ManifestType::CONFIGURATION,
            (int) $machine->config_version,
            null,
            $now,
        );
        $employees = $this->typeStatus(
            $device,
            $states->get(ManifestType::EMPLOYEES->value),
            ManifestType::EMPLOYEES,
            (int) $machine->employee_manifest_version,
            null,
            $now,
        );

        return [
            'configuration' => $configuration,
            'employees' => $employees,
            'biometrics' => ['supported' => false],
            'sync_state' => $this->overallState([$configuration['state'], $employees['state']])->value,
        ];
    }

    private function typeStatus(
        Device $device,
        ?DeviceManifestState $state,
        ManifestType $type,
        int $serverVersion,
        ?string $serverHash,
        Carbon $now,
    ): array {
        $fallbackApplied = $type === ManifestType::CONFIGURATION
            ? $device->config_version_applied
            : $device->employee_manifest_version_applied;
        $appliedVersion = $state?->applied_version ?? $fallbackApplied;
        $changed = $appliedVersion === null || (int) $appliedVersion !== $serverVersion;
        $syncState = $this->resolveState($device, $state, $serverVersion, $appliedVersion, $changed, $now);

        $result = [
            'server_version' => $serverVersion,
            'applied_version' => $appliedVersion !== null ? (int) $appliedVersion : null,
            'changed' => $changed,
            'state' => $syncState->value,
            'last_ack_at' => $state?->last_ack_at?->copy()->utc()->toIso8601String(),
            'last_ack_status' => $state?->last_ack_status?->value,
            'last_error_code' => $state?->last_error_code,
        ];

        if ($serverHash !== null) {
            $result['server_hash'] = $serverHash;
            $result['applied_hash'] = $state?->applied_hash;
        }

        return $result;
    }

    private function resolveState(
        Device $device,
        ?DeviceManifestState $state,
        int $serverVersion,
        mixed $appliedVersion,
        bool $changed,
        Carbon $now,
    ): ManifestSyncState {
        if ($state?->last_ack_status === ManifestAckStatus::FAILED) {
            return ManifestSyncState::ERROR;
        }

        $staleAfterMinutes = (int) config('vending.manifests.stale_after_minutes', 10);
        $staleVersionLag = (int) config('vending.manifests.stale_version_lag', 3);
        $lastSeenStale = $device->last_seen_at !== null
            && $device->last_seen_at->lt($now->copy()->subMinutes($staleAfterMinutes));
        $versionStale = $appliedVersion !== null
            && ($serverVersion - (int) $appliedVersion) >= $staleVersionLag;

        if ($lastSeenStale || $versionStale) {
            return ManifestSyncState::STALE;
        }

        return $changed ? ManifestSyncState::PENDING : ManifestSyncState::SYNCED;
    }

    /** @param array<int,string> $states */
    private function overallState(array $states): ManifestSyncState
    {
        foreach ([ManifestSyncState::ERROR, ManifestSyncState::STALE, ManifestSyncState::PENDING] as $candidate) {
            if (in_array($candidate->value, $states, true)) {
                return $candidate;
            }
        }

        return ManifestSyncState::SYNCED;
    }
}
