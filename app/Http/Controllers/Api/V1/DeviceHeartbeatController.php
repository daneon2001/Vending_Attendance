<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeviceHeartbeatRequest;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use App\Services\Vending\MachineConfigurationVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DeviceHeartbeatController extends Controller
{
    public function __invoke(DeviceHeartbeatRequest $request, MachineConfigurationVersionService $versions): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('vending_device');
        $data = $request->validated();
        $now = now();
        $deviceTime = Carbon::parse($data['device_time']);
        $clockDrift = $now->timestamp - $deviceTime->timestamp;
        $driftThreshold = (int) config('vending.device.clock_drift_warning_seconds', 300);
        $driftWarning = abs($clockDrift) > $driftThreshold;
        $machine = $device->vendingMachine()->firstOrFail();
        $appliedVersion = isset($data['config_version_applied']) ? (int) $data['config_version_applied'] : null;

        $device->forceFill([
            'app_version' => $data['app_version'] ?? $device->app_version,
            'platform_version' => $data['platform_version'] ?? $device->platform_version,
            'config_version_applied' => $appliedVersion ?? $device->config_version_applied,
            'battery_level' => $data['battery_level'] ?? null,
            'storage_free_mb' => $data['storage_free_mb'] ?? null,
            'pending_events_count' => $data['pending_events_count'] ?? null,
            'device_time' => $deviceTime,
            'clock_drift_seconds' => $clockDrift,
            'last_seen_at' => $now,
            'last_heartbeat_at' => $now,
            'last_status' => $driftWarning ? 'CLOCK_DRIFT_WARNING' : 'OK',
        ])->save();

        if ($driftWarning) {
            AuditLogger::log('device.clock_drift_detected', $device, 'Device clock drift threshold exceeded.', [
                'clock_drift_seconds' => $clockDrift,
                'threshold_seconds' => $driftThreshold,
            ]);
        }
        if ($appliedVersion !== null && $appliedVersion > (int) $machine->config_version) {
            AuditLogger::log('device.invalid_configuration_version', $device, 'Device reported a future configuration version.', [
                'device_version' => $appliedVersion,
                'server_version' => (int) $machine->config_version,
            ]);
        }

        return response()->json([
            'ok' => true,
            'server_time' => $now->utc()->toIso8601String(),
            'next_heartbeat_seconds' => (int) config('vending.device.next_heartbeat_seconds', 60),
            'clock_drift_seconds' => $clockDrift,
            'clock_drift_warning' => $driftWarning,
            'clock_drift_threshold_seconds' => $driftThreshold,
            'server_config_version' => (int) $machine->config_version,
            'configuration_changed' => $versions->hasChanged($machine, $appliedVersion),
        ]);
    }
}
