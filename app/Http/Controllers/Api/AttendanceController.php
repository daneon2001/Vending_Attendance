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
            'device_id' => ['required', 'integer'],
            'log_type' => ['required', 'integer'],
            'log_date' => ['required', 'date'],
        ]);

        $employee = Employee::find($validated['employee_id']);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'company_id' => $employee->company_id,
            'location_id' => $employee->base_location_id,
            'device_id' => $validated['device_id'],
            'log_date' => Carbon::parse($validated['log_date']),
            'log_type' => $validated['log_type'],
        ]);

        return response()->json(['message' => 'Attendance stored (dummy).'], 201);
    }

    public function listByEmployee(Employee $employee, Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = $employee->attendanceLogs()->latest('log_date');

        if ($request->filled('from')) {
            $query->where('log_date', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('log_date', '<=', $request->date('to'));
        }

        return response()->json($query->paginate(20));
    }

    public function sendToFortia(): JsonResponse
    {
        // TODO: llamar FortiaAttendanceService::sendPendingAttendanceLogs()
        return response()->json([
            'message' => 'Send to Fortia scheduled/TODO',
        ], 202);
    }
}
