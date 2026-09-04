<?php

namespace App\Http\Requests\Vending;

use Illuminate\Foundation\Http\FormRequest;

class CreateDeviceProvisioningTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['expires_in_minutes' => ['nullable', 'integer', 'between:5,1440']];
    }
}
