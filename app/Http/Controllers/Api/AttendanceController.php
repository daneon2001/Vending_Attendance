<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function storeFromDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'clock_id' => ['required', 'exists:clocks,id'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'log_type' => ['required', 'string', 'max:20'],
            'log_date' => ['required', 'date'],
            'local_id' => ['nullable', 'string', 'max:100'],
            'device_timestamp' => ['nullable', 'date'],
            'raw_payload' => ['nullable'],
        ]);

        // Endpoint pensado para la app on-prem (Python) que envia checadas reales.
        $employee = Employee::findOrFail($validated['employee_id']);

        $nextLogId = (AttendanceLog::max('log_id') ?? 0) + 1;

        $rawPayload = $validated['raw_payload'] ?? null;
        if (! is_null($rawPayload)) {
            $rawPayload = is_array($rawPayload) ? $rawPayload : ['value' => $rawPayload];
        }

        $enrichedPayload = array_filter([
            'local_id' => $validated['local_id'] ?? null,
            'device_timestamp' => $validated['device_timestamp'] ?? null,
        ]);

        if (! empty($enrichedPayload)) {
            $rawPayload = array_merge($rawPayload ?? [], $enrichedPayload);
        }

        $log = AttendanceLog::create([
            'log_id' => $nextLogId,
            'employee_id' => $employee->id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'company_id' => $employee->company_id,
            'location_id' => $validated['location_id'] ?? $employee->base_location_id,
            'device_id' => $validated['clock_id'],
            'log_date' => Carbon::parse($validated['log_date']),
            'log_type' => $validated['log_type'],
            'raw_payload' => $rawPayload ?: null,
        ]);

        return response()->json([
            'cloud_id' => $log->id,
            'received' => true,
        ], 201);
    }

    public function listByEmployee(Employee $employee, Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = $employee->attendanceLogs()->latest('log_date');

        if ($request->filled('from')) {
            $fromInput = $request->input('from');
            $from = $this->normalizeDateBoundary($fromInput, true);
            $query->where('log_date', '>=', $from);
        }

        if ($request->filled('to')) {
            $toInput = $request->input('to');
            $to = $this->normalizeDateBoundary($toInput, false);
            $query->where('log_date', '<=', $to);
        }

        $logs = $query->paginate($request->integer('per_page', 20))->withQueryString();

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }

    private function normalizeDateBoundary(string $value, bool $isStart): Carbon
    {
        $date = Carbon::parse($value);

        if (! str_contains($value, ':')) {
            return $isStart ? $date->copy()->startOfDay() : $date->copy()->endOfDay();
        }

        return $isStart ? $date->copy() : $date->copy();
    }

    public function sendToFortia(): JsonResponse
    {
        // TODO: llamar FortiaAttendanceService::sendPendingAttendanceLogs()
        return response()->json([
            'message' => 'Send to Fortia scheduled/TODO',
        ], 202);
    }
}
