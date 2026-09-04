<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Vending\DeviceManifestStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceManifestStatusController extends Controller
{
    public function __invoke(Request $request, DeviceManifestStatusService $statuses): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('vending_device');

        return response()->json($statuses->status($device));
    }
}
