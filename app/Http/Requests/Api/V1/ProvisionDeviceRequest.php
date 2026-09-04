<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provisioning_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
            'device_serial' => ['required', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:40'],
            'platform_version' => ['nullable', 'string', 'max:80'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'hardware_model' => ['nullable', 'string', 'max:120'],
        ];
    }
}
