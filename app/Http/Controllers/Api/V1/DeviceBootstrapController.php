<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeviceBootstrapRequest;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use App\Services\Vending\MachineConfigurationVersionService;
use Illuminate\Http\JsonResponse;

class DeviceBootstrapController extends Controller
{
    public function __invoke(DeviceBootstrapRequest $request, MachineConfigurationVersionService $versions): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('vending_device');
        $machine = $device->vendingMachine()->firstOrFail();
        $geofence = $machine->geofences()->effectiveAt(now())->first();
        $appliedVersion = $request->validated('config_version_applied');

        if ($appliedVersion !== null) {
            $device->forceFill(['config_version_applied' => $appliedVersion])->save();

            if ((int) $appliedVersion > (int) $machine->config_version) {
                AuditLogger::log('device.invalid_configuration_version', $device, 'Device reported a future configuration version.', [
                    'device_version' => (int) $appliedVersion,
                    'server_version' => (int) $machine->config_version,
                ]);
            }
        }

        $configurationChanged = $versions->hasChanged($machine, $appliedVersion !== null ? (int) $appliedVersion : null);

        AuditLogger::log('device.bootstrap', $device, 'Device bootstrap served.', [
            'machine_uuid' => $machine->uuid,
            'configuration_changed' => $configurationChanged,
            'device_config_version' => $appliedVersion,
            'server_config_version' => (int) $machine->config_version,
        ]);

        return response()->json([
            'device' => [
                'uuid' => $device->uuid,
                'status' => $device->status->value,
            ],
            'machine' => [
                'uuid' => $machine->uuid,
                'machine_code' => $machine->machine_code,
                'status' => $machine->status->value,
                'config_version' => (int) $machine->config_version,
                'timezone' => $machine->timezone,
            ],
            'geofence' => $geofence ? [
                'uuid' => $geofence->uuid,
                'version' => (int) $geofence->version,
                'type' => $geofence->shape->value,
                'center_latitude' => (float) $geofence->center_latitude,
                'center_longitude' => (float) $geofence->center_longitude,
                'radius_m' => (int) $geofence->radius_m,
                'minimum_acceptable_accuracy_m' => $geofence->minimum_acceptable_accuracy_m,
                'tolerance_m' => (float) $geofence->tolerance_m,
            ] : null,
            'configuration_changed' => $configurationChanged,
            'server_time' => now()->utc()->toIso8601String(),
            'sync' => [
                'employee_manifest_version' => null,
                'biometric_manifest_version' => null,
            ],
        ]);
    }
}
