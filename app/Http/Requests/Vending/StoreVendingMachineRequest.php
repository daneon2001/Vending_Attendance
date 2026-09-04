<?php

namespace App\Http\Requests\Vending;

use App\Enums\Vending\CoordinateSource;
use App\Enums\Vending\VendingMachineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVendingMachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sybi_id' => ['nullable', 'string', 'max:255', 'unique:vending_machines,sybi_id'],
            'machine_code' => ['required', 'string', 'max:100', 'unique:vending_machines,machine_code'],
            'operational_code' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'locality' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'regex:/^\d{5}$/'],
            'country' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'coordinate_source' => ['nullable', Rule::enum(CoordinateSource::class)],
            'coordinates_verified' => ['sometimes', 'boolean'],
            'coordinates_verified_at' => ['nullable', 'date'],
            'timezone' => ['required', 'timezone'],
            'status' => ['required', Rule::enum(VendingMachineStatus::class)],
            'default_geofence_radius_m' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'installed_at' => ['nullable', 'date'],
            'retired_at' => ['nullable', 'date', 'after_or_equal:installed_at'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $latitude = $this->input('latitude');
            $longitude = $this->input('longitude');
            if ($latitude !== null && $longitude !== null && (float) $latitude === 0.0 && (float) $longitude === 0.0) {
                $validator->errors()->add('latitude', 'La coordenada 0,0 no es válida para una máquina operativa.');
            }
            if ($this->boolean('coordinates_verified') && ($latitude === null || $longitude === null)) {
                $validator->errors()->add('coordinates_verified', 'Las coordenadas verificadas requieren latitud y longitud.');
            }
        }];
    }
}
