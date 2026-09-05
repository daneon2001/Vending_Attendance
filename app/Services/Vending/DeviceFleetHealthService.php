<?php

namespace App\Services\Vending;

use App\Enums\DeviceStatus;
use App\Enums\Vending\DeviceFleetHealthStatus;
use App\Enums\Vending\MobileVersionStatus;
use App\Models\Device;
use App\Models\MobileReleasePolicy;
use Illuminate\Support\Carbon;

class DeviceFleetHealthService
{
    public function __construct(
        private readonly DeviceManifestStatusService $manifests,
        private readonly MobileVersionPolicyService $versions,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(
        Device $device,
        ?Carbon $now = null,
        ?MobileReleasePolicy $policy = null,
        bool $policyResolved = false,
    ): array {
        $now ??= now();
        $lifecycle = $device->status instanceof DeviceStatus
            ? $device->status
            : DeviceStatus::from((string) $device->status);
        $manifest = $this->manifests->summary($device, $now);
        $version = $policyResolved
            ? $this->versions->evaluateWithPolicy($device, $policy)
            : $this->versions->evaluate($device, $policy);
        $lastHeartbeat = $device->last_heartbeat_at;
        $heartbeatAge = $lastHeartbeat === null
            ? null
            : (int) $lastHeartbeat->diffInSeconds($now, false);
        $reasons = [];

        $fixed = match ($lifecycle) {
            DeviceStatus::PENDING => DeviceFleetHealthStatus::PENDING,
            DeviceStatus::SUSPENDED => DeviceFleetHealthStatus::SUSPENDED,
            DeviceStatus::REVOKED => DeviceFleetHealthStatus::REVOKED,
            DeviceStatus::RETIRED => DeviceFleetHealthStatus::RETIRED,
            DeviceStatus::ACTIVE => null,
        };
        if ($fixed !== null) {
            return $this->result($fixed, ['LIFECYCLE_'.$fixed->value], $heartbeatAge, $manifest, $version);
        }

        $offlineAfter = (int) config('vending.device.health.offline_after_seconds', 600);
        if ($heartbeatAge === null || $heartbeatAge >= $offlineAfter) {
            return $this->result(
                DeviceFleetHealthStatus::OFFLINE,
                [$heartbeatAge === null ? 'HEARTBEAT_NEVER_RECEIVED' : 'HEARTBEAT_EXPIRED'],
                $heartbeatAge,
                $manifest,
                $version,
            );
        }

        if ($heartbeatAge >= (int) config('vending.device.health.degraded_after_seconds', 180)) {
            $reasons[] = 'HEARTBEAT_DELAYED';
        }
        if (abs((int) ($device->clock_drift_seconds ?? 0)) > (int) config('vending.device.health.clock_drift_seconds', 300)) {
            $reasons[] = 'CLOCK_DRIFT';
        }
        if ($device->storage_free_mb !== null && (int) $device->storage_free_mb < (int) config('vending.device.health.storage_free_mb', 256)) {
            $reasons[] = 'LOW_STORAGE';
        }
        if ((int) ($device->pending_events_count ?? 0) >= (int) config('vending.device.health.pending_events_count', 100)) {
            $reasons[] = 'OUTBOX_PRESSURE';
        }
        if (in_array($manifest['sync_state'], ['PENDING', 'ERROR', 'STALE'], true)) {
            $reasons[] = 'MANIFEST_'.$manifest['sync_state'];
        }
        if (in_array($version['status'], [MobileVersionStatus::UPDATE_REQUIRED->value, MobileVersionStatus::UNSUPPORTED->value], true)) {
            $reasons[] = 'APP_'.$version['status'];
        }
        if ($device->last_error_category
            && $device->last_error_at?->gte($now->copy()->subMinutes((int) config('vending.device.health.recent_error_minutes', 30)))) {
            $reasons[] = 'RECENT_'.$device->last_error_category;
        }

        return $this->result(
            $reasons === [] ? DeviceFleetHealthStatus::ONLINE : DeviceFleetHealthStatus::DEGRADED,
            array_values(array_unique($reasons)),
            $heartbeatAge,
            $manifest,
            $version,
        );
    }

    /** @return array<string, mixed> */
    private function result(
        DeviceFleetHealthStatus $status,
        array $reasons,
        ?int $heartbeatAge,
        array $manifest,
        array $version,
    ): array {
        return [
            'status' => $status->value,
            'reasons' => $reasons,
            'heartbeat_age_seconds' => $heartbeatAge,
            'manifest' => $manifest,
            'app_version' => $version,
        ];
    }
}
