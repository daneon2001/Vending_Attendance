<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GeofenceValidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'machine_uuid' => ['required', 'uuid', 'exists:vending_machines,uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'captured_at' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ((float) $this->input('latitude') === 0.0 && (float) $this->input('longitude') === 0.0) {
                $validator->errors()->add('latitude', 'La coordenada 0,0 no es válida para validación espacial.');
            }
        }];
    }
}
