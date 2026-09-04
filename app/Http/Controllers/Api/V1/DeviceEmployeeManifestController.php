<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeviceEmployeeManifestRequest;
use App\Models\Device;
use App\Services\Vending\EmployeeManifestService;
use Illuminate\Http\JsonResponse;

class DeviceEmployeeManifestController extends Controller
{
    public function __invoke(DeviceEmployeeManifestRequest $request, EmployeeManifestService $manifests): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('vending_device');
        $snapshot = $manifests->snapshot($device->vendingMachine()->firstOrFail());
        $knownVersion = $request->validated('known_version');

        if ($knownVersion !== null && (int) $knownVersion === $snapshot['manifest_version']) {
            return response()->json([
                'manifest_type' => $snapshot['manifest_type'],
                'manifest_version' => $snapshot['manifest_version'],
                'manifest_hash' => $snapshot['manifest_hash'],
                'changed' => false,
                'server_time' => $snapshot['server_time'],
            ]);
        }

        return response()->json(array_merge($snapshot, ['changed' => true]));
    }
}
