<?php

namespace App\Http\Controllers\FieldIdentity;

use App\Http\Controllers\Controller;
use App\Services\FieldIdentity\DeviceIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceIdentityController extends Controller
{
    public function __invoke(Request $request, DeviceIdentityService $service): JsonResponse
    {
        // Personal authentication must never be substituted with terminal HMAC.
        abort_unless($request->secure() || app()->environment('testing'), 403);
        $data = $request->validate([
            'employee_id' => 'prohibited', 'user_id' => 'prohibited', 'phone' => 'prohibited',
            'private_key' => 'prohibited', 'status' => 'prohibited',
            'otp_uuid' => 'sometimes|required|uuid', 'code' => 'sometimes|required|string|size:6',
            'device_uuid' => 'sometimes|required|uuid', 'purpose' => 'sometimes|required|in:ENROLLMENT,ACTOR',
            'challenge_uuid' => 'sometimes|required|uuid', 'signature' => 'sometimes|required|string|max:160',
        ]);
        $action = $request->route('identity_action');
        $result = match ($action) {
            'profile' => $service->profile(),
            'otp-send' => $service->sendOtp(),
            'otp-verify' => $service->verifyOtp($data['otp_uuid'] ?? '', $data['code'] ?? ''),
            'register' => $service->register($request->only(['operation_uuid', 'device_uuid', 'otp_uuid',
                'public_key', 'platform', 'platform_version', 'app_version', 'hardware_model', 'replaces_uuid'])),
            'challenge' => $service->challenge($data['device_uuid'] ?? '', $data['purpose'] ?? 'ENROLLMENT'),
            'prove' => $service->prove($data['challenge_uuid'] ?? '', $data['signature'] ?? '', $data['purpose'] ?? 'ENROLLMENT'),
            'revoke' => $service->revoke($data['device_uuid'] ?? ''),
            default => abort(404),
        };

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }
}
