<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeTemplatesSyncRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('status')) {
            $this->merge([
                'status' => strtolower((string) $this->input('status')),
            ]);
        }

        if ($this->has('biometric_type')) {
            $this->merge([
                'biometric_type' => strtoupper((string) $this->input('biometric_type')),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => [
                'nullable',
                'string',
                'max:40',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $raw = trim((string) $value);
                    if ($raw === '') {
                        return;
                    }
                    if (preg_match('/^\d{14}$/', $raw) === 1) {
                        return;
                    }
                    if (strtotime($raw) !== false) {
                        return;
                    }
                    $fail('El campo since debe ser timestamp YmdHis o fecha valida.');
                },
            ],
            'location_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'biometric_type' => ['nullable', Rule::in(['FINGERPRINT', 'FACE', 'FACE_ID', 'ALL'])],
        ];
    }
}
