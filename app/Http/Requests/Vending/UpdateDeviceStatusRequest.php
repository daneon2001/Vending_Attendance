<?php

namespace App\Http\Requests\Vending;

use App\Enums\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                DeviceStatus::SUSPENDED->value,
                DeviceStatus::REVOKED->value,
                DeviceStatus::RETIRED->value,
            ])],
        ];
    }
}
