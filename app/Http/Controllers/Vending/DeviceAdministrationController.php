<?php

namespace App\Http\Controllers\Vending;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vending\CreateDeviceProvisioningTokenRequest;
use App\Http\Requests\Vending\RevokeDeviceProvisioningTokenRequest;
use App\Http\Requests\Vending\UpdateDeviceReleaseChannelRequest;
use App\Http\Requests\Vending\UpdateDeviceStatusRequest;
use App\Models\Device;
use App\Models\DeviceProvisioningToken;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;
use App\Services\Vending\DeviceLifecycleService;
use App\Services\Vending\DeviceProvisioningTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DeviceAdministrationController extends Controller
{
    public function createToken(
        CreateDeviceProvisioningTokenRequest $request,
        VendingMachine $vendingMachine,
        DeviceProvisioningTokenService $service,
    ): JsonResponse {
        $minutes = (int) ($request->validated('expires_in_minutes')
            ?? config('vending.device.provisioning_token_ttl_minutes', 30));
        $result = $service->create($vendingMachine, $request->user()?->id, now()->addMinutes($minutes));

        return response()->json([
            'token' => $result['plain_token'],
            'token_uuid' => $result['token']->uuid,
            'expires_at' => $result['token']->expires_at->toIso8601String(),
            'message' => 'El token se muestra una sola vez.',
        ], 201);
    }

    public function revokeToken(
        RevokeDeviceProvisioningTokenRequest $request,
        VendingMachine $vendingMachine,
        DeviceProvisioningToken $provisioningToken,
        DeviceProvisioningTokenService $service,
    ): RedirectResponse {
        abort_unless($provisioningToken->vending_machine_id === $vendingMachine->id, 404);
        $service->revoke($provisioningToken, $request->user()?->id, $request->validated('reason'));

        return back()->with('success', 'Token de provisioning revocado.');
    }

    public function updateStatus(
        UpdateDeviceStatusRequest $request,
        VendingMachine $vendingMachine,
        Device $device,
        DeviceLifecycleService $service,
    ): RedirectResponse {
        abort_unless($device->vending_machine_id === $vendingMachine->id, 404);
        $service->transition($device, DeviceStatus::from($request->validated('status')));

        return back()->with('success', 'Estado del dispositivo actualizado.');
    }

    public function updateReleaseChannel(
        UpdateDeviceReleaseChannelRequest $request,
        VendingMachine $vendingMachine,
        Device $device,
    ): RedirectResponse {
        abort_unless($device->vending_machine_id === $vendingMachine->id, 404);
        $before = ['release_channel' => $device->release_channel, 'release_group' => $device->release_group];
        $device->forceFill($request->safe()->only(['release_channel', 'release_group']))->save();
        AuditLogger::log('device.release_channel_updated', $device, 'Device release channel updated.', [
            'old_values' => $before,
            'new_values' => ['release_channel' => $device->release_channel, 'release_group' => $device->release_group],
        ]);

        return back()->with('success', 'Canal de release actualizado.');
    }
}
