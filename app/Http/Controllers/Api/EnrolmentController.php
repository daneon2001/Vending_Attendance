<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrolmentController extends Controller
{
    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'clock_id' => ['required', 'exists:clocks,id'],
            'enrolment_type' => ['required', 'string', 'max:50'],
            'template_vendor_id' => ['nullable', 'string', 'max:191'],
            'device_serial' => ['nullable', 'string', 'max:191'],
            'performed_at' => ['required', 'date'],
        ]);

        // Endpoint pensado para la app on-prem (Python) que confirma el enrolamiento.
        $fingerprint = EmployeeFingerprint::updateOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'clock_id' => $validated['clock_id'],
            ],
            [
                'status' => 'enrolled',
                'enrolled_at' => $validated['performed_at'],
                'vendor_template_id' => $validated['template_vendor_id'] ?? null,
                'deleted_at' => null,
            ]
        );

        $employee = Employee::find($validated['employee_id']);
        if ($employee) {
            $employee->refreshFingerprintFlag();
        } else {
            Employee::query()
                ->whereKey($validated['employee_id'])
                ->update(['has_fingerprint' => true]);
        }

        return response()->json([
            'success' => true,
            'employee_id' => (int) $validated['employee_id'],
            'clock_id' => (int) $validated['clock_id'],
        ]);
    }
}
