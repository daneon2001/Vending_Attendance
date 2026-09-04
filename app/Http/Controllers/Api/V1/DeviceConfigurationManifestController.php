<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Vending\MachineConfigurationManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceConfigurationManifestController extends Controller
{
    public function __invoke(Request $request, MachineConfigurationManifestService $manifests): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('vending_device');

        return response()->json($manifests->snapshot($device));
    }
}
