<?php

namespace App\Http\Requests\Vending;

use App\Enums\Vending\GeofenceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMachineGeofenceRequest extends FormRequest
{
    public const EDITOR_LIMITS = [
        'radius_m' => ['min' => 1, 'max' => 100000],
        'minimum_acceptable_accuracy_m' => ['min' => 0, 'max' => 100000],
        'tolerance_m' => ['min' => 0, 'max' => 100000],
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'center_latitude' => ['required', 'numeric', 'between:-90,90'],
            'center_longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:'.self::EDITOR_LIMITS['radius_m']['min'], 'max:'.self::EDITOR_LIMITS['radius_m']['max']],
            'minimum_acceptable_accuracy_m' => ['nullable', 'numeric', 'min:'.self::EDITOR_LIMITS['minimum_acceptable_accuracy_m']['min'], 'max:'.self::EDITOR_LIMITS['minimum_acceptable_accuracy_m']['max']],
            'tolerance_m' => ['nullable', 'numeric', 'min:'.self::EDITOR_LIMITS['tolerance_m']['min'], 'max:'.self::EDITOR_LIMITS['tolerance_m']['max']],
            'expected_config_version' => ['sometimes', 'integer', 'min:1'],
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

    public function messages(): array
    {
        return [
            'center_latitude.*' => 'Selecciona una latitud válida para el centro.',
            'center_longitude.*' => 'Selecciona una longitud válida para el centro.',
            'radius_m.*' => 'El radio debe ser un número entero entre '.self::EDITOR_LIMITS['radius_m']['min'].' y '.self::EDITOR_LIMITS['radius_m']['max'].' metros.',
            'minimum_acceptable_accuracy_m.*' => 'La precisión debe estar entre 0 y '.self::EDITOR_LIMITS['minimum_acceptable_accuracy_m']['max'].' metros.',
            'tolerance_m.*' => 'La tolerancia debe estar entre 0 y '.self::EDITOR_LIMITS['tolerance_m']['max'].' metros.',
            'expected_config_version.*' => 'Recarga la página antes de guardar la configuración.',
            'status.*' => 'Selecciona un estado válido para la geocerca.',
        ];
    }
}
