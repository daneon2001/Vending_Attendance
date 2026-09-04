<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DeviceHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'app_version' => ['nullable', 'string', 'max:80'],
            'platform_version' => ['nullable', 'string', 'max:80'],
            'config_version_applied' => ['nullable', 'integer', 'min:1'],
            'battery_level' => ['nullable', 'numeric', 'between:0,100'],
            'storage_free_mb' => ['nullable', 'integer', 'min:0'],
            'pending_events_count' => ['nullable', 'integer', 'min:0'],
            'device_time' => ['required', 'date'],
        ];
    }
}
