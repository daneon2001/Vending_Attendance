<?php

namespace App\Http\Requests\Vending;

use App\Enums\Vending\AssignmentStatus;
use App\Enums\Vending\AssignmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeMachineAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'assignment_type' => ['required', Rule::enum(AssignmentType::class)],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'attendance_allowed' => ['required', 'boolean'],
            'enrollment_allowed' => ['required', 'boolean'],
            'maintenance_allowed' => ['required', 'boolean'],
            'status' => ['sometimes', Rule::in([AssignmentStatus::ACTIVE->value, AssignmentStatus::INACTIVE->value])],
            'source' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
