<?php

namespace App\Http\Controllers\Api\OnPrem;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnPrem\StoreOnPremHeartbeatRequest;
use App\Models\Clock;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class OnPremHeartbeatController extends Controller
{
    public function store(StoreOnPremHeartbeatRequest $request): JsonResponse
    {
        /** @var Device|null $device */
        $device = $request->attributes->get('onprem_device');

        if (! $device) {
            return $this->errorResponse(401, 'DEVICE_NOT_ACTIVE', 'Device not resolved.');
        }

        $validated = $request->validated();
        $payloadHash = (string) ($request->attributes->get('onprem_payload_hash') ?? hash('sha256', (string) $request->getContent()));

        if ((string) $validated['device_serial'] !== (string) $device->device_serial) {
            return $this->errorResponse(422, 'VALIDATION_FAILED', 'device_serial mismatch.');
        }

        $now = now();
        $lastStatus = $validated['status_message']
            ?? ((array_key_exists('device_ok', $validated) && $validated['device_ok'] === false) ? 'DEVICE_ERROR' : 'OK');

        $device->forceFill([
            'clock_id' => $validated['clock_id'] ?? $device->clock_id,
            'unit_id' => $validated['unit_id'] ?? $device->unit_id,
            'company_id' => $validated['company_id'] ?? $device->company_id,
            'last_seen_at' => $now,
            'last_heartbeat_at' => $now,
            'last_status' => is_string($lastStatus) ? $lastStatus : null,
        ])->save();

        $clockId = $validated['clock_id'] ?? $device->clock_id;
        if ($clockId) {
            $clockUpdates = [];

            if (Schema::hasColumn('clocks', 'last_heartbeat_at')) {
                $clockUpdates['last_heartbeat_at'] = $now;
            }

            if (Schema::hasColumn('clocks', 'last_status_message')) {
                $clockUpdates['last_status_message'] = is_string($lastStatus) ? $lastStatus : null;
            }

            if (Schema::hasColumn('clocks', 'last_seen_ip')) {
                $clockUpdates['last_seen_ip'] = $request->ip();
            }

            if ($clockUpdates !== []) {
                Clock::query()
                    ->whereKey($clockId)
                    ->update($clockUpdates);
            }
        }

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

        return response()->json([
            'ok' => true,
            'server_time' => $now->utc()->toIso8601String(),
            'next_heartbeat_seconds' => (int) config('onprem.next_heartbeat_seconds', 15),
            'device' => [
                'device_serial' => $device->device_serial,
                'clock_id' => $device->clock_id,
                'unit_id' => $device->unit_id,
                'company_id' => $device->company_id,
                'is_active' => (bool) $device->is_active,
                'last_heartbeat_at' => optional($device->last_heartbeat_at)->utc()->toIso8601String(),
                'last_status' => $device->last_status,
            ],
        ], 200);
    }

    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'server_time' => now()->utc()->toIso8601String(),
        ], 200);
    }

    private function errorResponse(int $status, string $reason, string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $reason,
            'reason' => $reason,
            'message' => $message,
        ], $status);
    }
}
