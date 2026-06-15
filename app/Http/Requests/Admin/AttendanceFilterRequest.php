<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'employee' => ['nullable', 'string', 'max:150'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'device_id' => ['nullable', 'integer', 'exists:clocks,id'],
            'type' => ['nullable', Rule::in(['in', 'out', 'unknown', '0', '1', '2', '3', '4'])],
            'source' => ['nullable', Rule::in(config('attendance.sources', ['sync', 'manual', 'import', 'api']))],
            'status' => ['nullable', Rule::in(config('attendance.statuses', ['valida', 'anulada', 'corregida']))],
            'per_page' => ['nullable', 'integer', 'between:10,200'],
            'view_mode' => ['nullable', Rule::in(['grouped', 'raw'])],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', 'max:80'],
            'format' => ['nullable', Rule::in(['csv', 'excel'])],
        ];
    }
}
