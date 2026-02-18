<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'device_id' => ['nullable', 'integer', 'exists:clocks,id'],
            'log_date' => ['required', 'date'],
            'log_type' => ['required', Rule::in(['in', 'out', 'unknown', '0', '1', '2', '3', '4'])],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
