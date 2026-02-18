<?php

namespace App\Http\Controllers\Api\OnPrem;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnPrem\StoreOnPremHeartbeatRequest;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

class OnPremHeartbeatController extends Controller
{
    public function store(StoreOnPremHeartbeatRequest $request): JsonResponse
    {
        /** @var Device|null $device */
        $device = $request->attributes->get('onprem_device');

        if (! $device) {
            return response()->json([
                'message' => 'Device not resolved.',
            ], 401);
        }

        $validated = $request->validated();
        $payloadHash = (string) ($request->attributes->get('onprem_payload_hash') ?? hash('sha256', (string) $request->getContent()));

        if ((string) $validated['device_serial'] !== (string) $device->device_serial) {
            return response()->json([
                'message' => 'device_serial mismatch.',
            ], 422);
        }

        foreach (['clock_id', 'unit_id', 'company_id'] as $field) {
            $requestValue = $validated[$field] ?? null;
            $deviceValue = $device->{$field};

            if ($requestValue !== null && $deviceValue !== null && (int) $requestValue !== (int) $deviceValue) {
                return response()->json([
                    'message' => sprintf('%s mismatch for device.', $field),
                ], 422);
            }
        }

        $device->forceFill([
            'clock_id' => $validated['clock_id'] ?? $device->clock_id,
            'unit_id' => $validated['unit_id'] ?? $device->unit_id,
            'company_id' => $validated['company_id'] ?? $device->company_id,
            'last_seen_at' => now(),
        ])->save();

        AuditLogger::log(
            event: 'onprem.heartbeat.received',
            auditable: $device,
            description: 'Heartbeat received',
            metadata: [
                'device_serial' => $device->device_serial,
                'clock_id' => $validated['clock_id'] ?? $device->clock_id,
                'unit_id' => $validated['unit_id'] ?? $device->unit_id,
                'company_id' => $validated['company_id'] ?? $device->company_id,
                'payload_hash' => $payloadHash,
                'ip' => $request->ip(),
                'app_version' => $validated['app_version'] ?? null,
                'api_ok' => $validated['api_ok'] ?? null,
                'device_ok' => $validated['device_ok'] ?? null,
                'pending_count' => $validated['pending_count'] ?? null,
                'last_event_at_utc' => $validated['last_event_at_utc'] ?? null,
                'last_event_at_local' => $validated['last_event_at_local'] ?? null,
                'tz' => $validated['tz'] ?? null,
                'ip_local' => $validated['ip_local'] ?? null,
                'port' => $validated['port'] ?? null,
                'status_message' => $validated['status_message'] ?? null,
            ],
        );

        $response = [
            'ok' => true,
            'server_time_utc' => now()->utc()->toIso8601String(),
        ];

        $configOverrides = array_filter(
            (array) config('onprem.config_overrides', []),
            static fn ($value): bool => ! is_null($value) && $value !== '',
        );

        if ($configOverrides !== []) {
            $response['config_overrides'] = $configOverrides;
        }

        return response()->json($response);
    }

    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'server_time_utc' => now()->utc()->toIso8601String(),
        ]);
    }
}
