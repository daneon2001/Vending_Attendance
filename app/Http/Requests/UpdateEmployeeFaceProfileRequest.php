<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeFaceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('face_status')) {
            $this->merge([
                'face_status' => strtolower((string) $this->input('face_status')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'face_enabled' => ['sometimes', 'boolean'],
            'face_status' => ['sometimes', 'string', Rule::in(['none', 'pending', 'enrolled', 'ready', 'review_required', 'disabled'])],
            'face_samples_count' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'face_template_version' => ['sometimes', 'nullable', 'string', 'max:80'],
            'face_quality_score' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'face_meta' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
