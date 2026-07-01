<?php

namespace App\Services\Audit;

use App\Models\AuditCleanupSetting;
use App\Models\Clock;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;

class HeartbeatAuditService
{
    /**
     * @return array{event:string,description:string,metadata:array<string,mixed>}|null
     */
    public function buildAuditEntry(
        Device $device,
        ?Clock $clock,
        ?string $previousMonitoringStatus,
        string $currentMonitoringStatus,
        array $metadata
    ): ?array {
        $normalizedPrevious = $this->normalizeStatus($previousMonitoringStatus);
        $normalizedCurrent = $this->normalizeStatus($currentMonitoringStatus);
        $intervalMinutes = $this->resolveIntervalMinutes();
        $cacheKey = $this->cacheKey($device, $clock);

        if ($normalizedPrevious !== $normalizedCurrent) {
            $this->markSampled($cacheKey, $intervalMinutes);

            return [
                'event' => 'onprem.heartbeat.status_changed',
                'description' => 'Heartbeat status changed',
                'metadata' => array_merge($metadata, [
                    'before_status' => $normalizedPrevious,
                    'after_status' => $normalizedCurrent,
                ]),
            ];
        }

        if (Cache::has($cacheKey)) {
            return null;
        }

        $this->markSampled($cacheKey, $intervalMinutes);

        return [
            'event' => 'onprem.heartbeat.sampled',
            'description' => 'Heartbeat sampled',
            'metadata' => array_merge($metadata, [
                'sampled_interval_minutes' => $intervalMinutes,
                'before_status' => $normalizedPrevious,
                'after_status' => $normalizedCurrent,
            ]),
        ];
    }

    private function normalizeStatus(?string $status): string
    {
        $normalized = mb_strtolower(trim((string) $status));

        if ($normalized === '' || $normalized === 'null') {
            return 'offline';
        }

        return $normalized;
    }

    private function resolveIntervalMinutes(): int
    {
        return max(
            1,
            (int) AuditCleanupSetting::singleton()->heartbeat_log_interval_minutes
        );
    }

    private function cacheKey(Device $device, ?Clock $clock): string
    {
        return sprintf(
            'audit:heartbeat:last-sampled:%s',
            $clock?->id ? 'clock-'.$clock->id : 'device-'.$device->device_serial
        );
    }

    private function markSampled(string $cacheKey, int $intervalMinutes): void
    {
        Cache::put($cacheKey, now()->timestamp, now()->addMinutes($intervalMinutes));
    }
}
