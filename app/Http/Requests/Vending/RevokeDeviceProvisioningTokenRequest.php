<?php

namespace App\Http\Requests\Vending;

use Illuminate\Foundation\Http\FormRequest;

class RevokeDeviceProvisioningTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
