<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Vending\AttendanceReceiptErrorCode;
use App\Enums\Vending\AttendanceReceiptStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Vending\VendingAttendanceBatchReceiverService;
use App\Services\Vending\VendingAttendanceMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class DeviceAttendanceEventBatchController extends Controller
{
    public function __invoke(
        Request $request,
        VendingAttendanceBatchReceiverService $receiver,
        VendingAttendanceMetricsService $metrics,
    ): JsonResponse {
        /** @var Device $device */
        $device = $request->attributes->get('vending_device');
        try {
            $result = $receiver->receive($device, $request->all());
        } catch (Throwable $exception) {
            report($exception);
            $metrics->record($device, AttendanceReceiptStatus::REJECTED);
            $result = [
                'status' => AttendanceReceiptStatus::REJECTED->value,
                'error_code' => AttendanceReceiptErrorCode::INTERNAL_RECEIVER_ERROR->value,
                'http_status' => 500,
            ];
        }
        $status = (int) ($result['http_status'] ?? 200);
        unset($result['http_status']);

        return response()->json(array_merge($result, [
            'server_time' => now()->utc()->toIso8601String(),
        ]), $status);
    }
}
