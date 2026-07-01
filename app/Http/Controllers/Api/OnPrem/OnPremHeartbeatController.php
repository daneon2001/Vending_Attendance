<?php

namespace App\Http\Controllers\Api\OnPrem;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnPrem\StoreOnPremHeartbeatRequest;
use App\Models\Clock;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\HeartbeatAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OnPremHeartbeatController extends Controller
{
    public function __construct(
        private readonly HeartbeatAuditService $heartbeatAuditService
    ) {
    }

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
        [$clock, $clockMatchedBy] = $this->resolveClockForHeartbeat($device, $validated);
        $previousMonitoringStatus = $clock?->monitoring_status;
        $monitoringStatus = (($validated['device_ok'] ?? true) === false || ($validated['api_ok'] ?? true) === false)
            ? 'warning'
            : 'online';

        $device->forceFill([
            'clock_id' => $clock?->id ?? $validated['clock_id'] ?? $device->clock_id,
            'unit_id' => $validated['unit_id'] ?? $clock?->location_id ?? $device->unit_id,
            'company_id' => $validated['company_id'] ?? $clock?->company_id ?? $device->company_id,
            'last_seen_at' => $now,
            'last_heartbeat_at' => $now,
            'last_status' => is_string($lastStatus) ? $lastStatus : null,
        ])->save();

        $clockUpdated = false;
        if ($clock) {
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

            if (Schema::hasColumn('clocks', 'monitoring_status')) {
                $clockUpdates['monitoring_status'] = $monitoringStatus;
            }

            if (Schema::hasColumn('clocks', 'program_status')) {
                $clockUpdates['program_status'] = 'online';
            }

            if ($clockUpdates !== []) {
                $clock->forceFill($clockUpdates)->save();
                $clockUpdated = true;
            }
        }

        Log::info('onprem.heartbeat.received', [
            'clock_id' => $clock?->id ?? $validated['clock_id'] ?? $device->clock_id,
            'device_serial' => $device->device_serial,
            'unit_id' => $validated['unit_id'] ?? $clock?->location_id ?? $device->unit_id,
            'company_id' => $validated['company_id'] ?? $clock?->company_id ?? $device->company_id,
            'received_at' => $now->toIso8601String(),
            'ip' => $request->ip(),
            'status_message' => $lastStatus,
            'monitoring_status' => $monitoringStatus,
            'clock_matched_by' => $clockMatchedBy,
            'clock_updated' => $clockUpdated,
        ]);

        if (! $clock) {
            Log::warning('onprem.heartbeat.clock_not_resolved', [
                'device_serial' => $device->device_serial,
                'payload_clock_id' => $validated['clock_id'] ?? null,
                'device_clock_id' => $device->clock_id,
                'unit_id' => $validated['unit_id'] ?? $device->unit_id,
                'company_id' => $validated['company_id'] ?? $device->company_id,
                'received_at' => $now->toIso8601String(),
            ]);
        }

        $auditPayload = $this->heartbeatAuditService->buildAuditEntry(
            device: $device,
            clock: $clock,
            previousMonitoringStatus: $previousMonitoringStatus,
            currentMonitoringStatus: $monitoringStatus,
            metadata: [
                'device_serial' => $device->device_serial,
                'clock_id' => $validated['clock_id'] ?? $device->clock_id,
                'unit_id' => $validated['unit_id'] ?? $device->unit_id,
                'company_id' => $validated['company_id'] ?? $device->company_id,
                'payload_hash' => $payloadHash,
                'ip' => $request->ip(),
                'clock_matched_by' => $clockMatchedBy,
                'clock_updated' => $clockUpdated,
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
            ]
        );

        if ($auditPayload !== null) {
            AuditLogger::log(
                event: $auditPayload['event'],
                auditable: $device,
                description: $auditPayload['description'],
                metadata: $auditPayload['metadata'],
            );
        }

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

    /**
     * @return array{0:\App\Models\Clock|null,1:string|null}
     */
    private function resolveClockForHeartbeat(Device $device, array $validated): array
    {
        if (! empty($validated['clock_id'])) {
            $clock = Clock::query()->find((int) $validated['clock_id']);
            if ($clock) {
                return [$clock, 'payload.clock_id'];
            }
        }

        if (! empty($device->clock_id)) {
            $clock = Clock::query()->find((int) $device->clock_id);
            if ($clock) {
                return [$clock, 'device.clock_id'];
            }
        }

        $serialCandidates = array_values(array_filter([
            trim((string) ($validated['device_serial'] ?? '')),
            trim((string) ($device->device_serial ?? '')),
        ]));

        foreach ($serialCandidates as $serial) {
            $clock = Clock::query()
                ->where('serial_number', $serial)
                ->first();

            if ($clock) {
                return [$clock, 'serial_number'];
            }
        }

        return [null, null];
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
