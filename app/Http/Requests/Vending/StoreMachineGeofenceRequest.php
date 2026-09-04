<?php

namespace App\Http\Requests\Vending;

use App\Enums\Vending\GeofenceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMachineGeofenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'center_latitude' => ['required', 'numeric', 'between:-90,90'],
            'center_longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:1', 'max:100000'],
            'minimum_acceptable_accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tolerance_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', Rule::in([GeofenceStatus::DRAFT->value, GeofenceStatus::ACTIVE->value])],
            'source' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ((float) $this->input('center_latitude') === 0.0 && (float) $this->input('center_longitude') === 0.0) {
                $validator->errors()->add('center_latitude', 'La coordenada 0,0 no es válida para una geocerca.');
            }
        }];
    }
}
