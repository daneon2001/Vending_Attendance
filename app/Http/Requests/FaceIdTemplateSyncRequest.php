<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FaceIdTemplateSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'min:1', 'required_without:fortia_employee_id'],
            'fortia_employee_id' => ['nullable', 'string', 'max:100', 'required_without:employee_id'],
            'employee_code' => ['nullable', 'string', 'max:100'],
            'template_hash' => ['required', 'string', 'max:191'],
            'embedding_encrypted' => ['required', 'string'],
            'quality_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'model_name' => ['required', 'string', 'max:120'],
            'model_version' => ['required', 'string', 'max:120'],
            'captured_at' => ['nullable', 'date'],
            'device_name' => ['nullable', 'string', 'max:191'],
            'device_serial' => ['nullable', 'string', 'max:191'],
            'runtime_version' => ['nullable', 'string', 'max:60'],
        ];
    }
}
