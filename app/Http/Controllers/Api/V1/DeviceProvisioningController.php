<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DeviceProvisioningException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProvisionDeviceRequest;
use App\Services\Vending\DeviceProvisioningService;
use Illuminate\Http\JsonResponse;

class DeviceProvisioningController extends Controller
{
    public function __invoke(ProvisionDeviceRequest $request, DeviceProvisioningService $service): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $service->provision($validated['provisioning_token'], $validated);
        } catch (DeviceProvisioningException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        }

        $device = $result['device'];

        return response()->json([
            'device' => [
                'uuid' => $device->uuid,
                'status' => $device->status->value,
            ],
            'machine' => [
                'uuid' => $device->vendingMachine->uuid,
                'config_version' => (int) $device->vendingMachine->config_version,
            ],
            'credentials' => [
                'scheme' => 'HMAC-SHA256',
                'device_id' => $device->uuid,
                'credential' => $result['credential'],
                'credential_version' => $result['credential_version'],
                'identity_header' => 'X-Device-Id',
            ],
            'message' => 'Credential is returned once and cannot be recovered later.',
        ], 201);
    }
}
