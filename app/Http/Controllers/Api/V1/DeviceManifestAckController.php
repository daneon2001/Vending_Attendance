<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeviceManifestAckRequest;
use App\Models\Device;
use App\Services\Vending\DeviceManifestAckService;
use Illuminate\Http\JsonResponse;

class DeviceManifestAckController extends Controller
{
    public function __invoke(DeviceManifestAckRequest $request, DeviceManifestAckService $acknowledgements): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('vending_device');
        $result = $acknowledgements->acknowledge($device, $request->validated());
        $status = (int) ($result['http_status'] ?? 200);
        unset($result['http_status']);

        return response()->json(array_merge($result, [
            'server_time' => now()->utc()->toIso8601String(),
        ]), $status);
    }
}
