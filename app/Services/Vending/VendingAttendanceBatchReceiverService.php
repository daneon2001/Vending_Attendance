<?php

namespace App\Services\Vending;

use App\Enums\Vending\AttendanceReceiptErrorCode;
use App\Enums\Vending\AttendanceReceiptStatus;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use Throwable;

class VendingAttendanceBatchReceiverService
{
    public function __construct(
        private readonly VendingAttendanceReceiverService $receiver,
        private readonly VendingAttendanceMetricsService $metrics,
    ) {}

    public function receive(Device $device, mixed $envelope): array
    {
        $limit = max(1, (int) config('vending.attendance.batch_max_events', 100));
        if (! is_array($envelope) || array_diff(array_keys($envelope), ['events']) !== []) {
            $this->metrics->record($device, AttendanceReceiptStatus::REJECTED);

            return $this->envelopeError(AttendanceReceiptErrorCode::INVALID_EVENT, 422);
        }
        $events = $envelope['events'] ?? null;

        if (! is_array($events) || $events === []) {
            $this->metrics->record($device, AttendanceReceiptStatus::REJECTED);

            return $this->envelopeError(AttendanceReceiptErrorCode::INVALID_EVENT, 422);
        }

        if (count($events) > $limit) {
            AuditLogger::log('attendance.batch_abuse', $device, 'Attendance batch exceeded configured limit.', [
                'device_id' => $device->getKey(),
                'submitted_count' => count($events),
                'configured_limit' => $limit,
            ]);
            $this->metrics->record($device, AttendanceReceiptStatus::REJECTED);

            return $this->envelopeError(AttendanceReceiptErrorCode::BATCH_LIMIT_EXCEEDED, 422, $limit);
        }

        $results = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                $this->metrics->record($device, AttendanceReceiptStatus::REJECTED);
                $results[] = [
                    'event_uuid' => null,
                    'status' => AttendanceReceiptStatus::REJECTED->value,
                    'error_code' => AttendanceReceiptErrorCode::INVALID_EVENT->value,
                ];

                continue;
            }

            try {
                $result = $this->receiver->receive($device, $event);
                unset($result['http_status']);
                $results[] = $result;
            } catch (Throwable) {
                $this->metrics->record($device, AttendanceReceiptStatus::REJECTED);
                $results[] = [
                    'event_uuid' => is_string($event['event_uuid'] ?? null) ? $event['event_uuid'] : null,
                    'status' => AttendanceReceiptStatus::REJECTED->value,
                    'error_code' => AttendanceReceiptErrorCode::INTERNAL_RECEIVER_ERROR->value,
                ];
            }
        }

        return ['results' => $results, 'http_status' => 200];
    }

    private function envelopeError(AttendanceReceiptErrorCode $code, int $status, ?int $limit = null): array
    {
        return array_filter([
            'status' => AttendanceReceiptStatus::REJECTED->value,
            'error_code' => $code->value,
            'limit' => $limit,
            'http_status' => $status,
        ], fn (mixed $value): bool => $value !== null);
    }
}
