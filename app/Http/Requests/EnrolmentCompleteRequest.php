<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrolmentCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('enrolment_type')) {
            $this->merge([
                'enrolment_type' => strtoupper((string) $this->input('enrolment_type')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'clock_id' => ['required', 'integer', 'exists:clocks,id'],
            'enrolment_type' => ['required', Rule::in(['FINGERPRINT', 'FACE'])],
            'template_vendor_id' => ['required', 'string', 'max:191'],
            'template_b64' => ['nullable', 'string'],
            'template_format' => ['nullable', 'string', 'max:40'],
            'device_serial' => ['nullable', 'string', 'max:191'],
            'performed_at' => ['required', 'date'],
        ];
    }
}
