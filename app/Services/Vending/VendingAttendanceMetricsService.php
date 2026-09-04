<?php

namespace App\Services\Vending;

use App\Enums\Vending\AttendanceReceiptStatus;
use App\Models\Device;
use App\Models\DeviceAttendanceMetric;
use App\Models\VendingAttendanceEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class VendingAttendanceMetricsService
{
    public function record(Device $device, AttendanceReceiptStatus $status, ?VendingAttendanceEvent $event = null): void
    {
        try {
            DB::table('device_attendance_metrics')->insertOrIgnore([
                'device_id' => $device->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $updates = ['updated_at' => now()];
            if ($status === AttendanceReceiptStatus::STORED && $event) {
                $delay = max(0, (int) $event->sync_delay_seconds);
                $updates = array_merge($updates, [
                    'stored_total' => DB::raw('stored_total + 1'),
                    'sync_delay_total_seconds' => DB::raw('sync_delay_total_seconds + '.(int) $delay),
                    'sync_delay_samples' => DB::raw('sync_delay_samples + 1'),
                    'sync_delay_max_seconds' => DB::raw(
                        'CASE WHEN sync_delay_max_seconds < '.(int) $delay
                        .' THEN '.(int) $delay.' ELSE sync_delay_max_seconds END',
                    ),
                    'last_attendance_received_at' => $event->received_at_server,
                    'geofence_mismatch_total' => DB::raw(
                        'geofence_mismatch_total + '.($event->geofence_discrepancy ? 1 : 0),
                    ),
                ]);
            } elseif ($status === AttendanceReceiptStatus::DUPLICATE) {
                $updates['duplicate_total'] = DB::raw('duplicate_total + 1');
            } elseif ($status === AttendanceReceiptStatus::REJECTED) {
                $updates['rejected_total'] = DB::raw('rejected_total + 1');
            }

            DB::table('device_attendance_metrics')
                ->where('device_id', $device->getKey())
                ->update($updates);
        } catch (Throwable) {
            // Operational metrics must never prevent evidence ingestion.
        }
    }

    public function summary(Device $device, ?Carbon $since = null): array
    {
        $since ??= now()->subDay();
        $events = VendingAttendanceEvent::query()
            ->where('device_id', $device->getKey())
            ->where('received_at_server', '>=', $since);
        $metric = DeviceAttendanceMetric::query()->where('device_id', $device->getKey())->first();

        return [
            'since' => $since->copy()->utc()->toIso8601String(),
            'events_received' => (clone $events)->count(),
            'last_attendance_received_at' => (clone $events)->max('received_at_server'),
            'sync_delay_average_seconds' => $this->roundNullable((clone $events)->avg('sync_delay_seconds')),
            'sync_delay_max_seconds' => $this->integerNullable((clone $events)->max('sync_delay_seconds')),
            'geofence_mismatches' => (clone $events)->where('geofence_discrepancy', true)->count(),
            'stored_total' => $metric?->stored_total ?? 0,
            'duplicate_total' => $metric?->duplicate_total ?? 0,
            'rejected_total' => $metric?->rejected_total ?? 0,
        ];
    }

    private function roundNullable(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function integerNullable(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
