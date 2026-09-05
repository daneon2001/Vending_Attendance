<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Vending\FleetErrorCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'app_build_number' => ['nullable', 'integer', 'min:1'],
            'platform_version' => ['nullable', 'string', 'max:80'],
            'config_version_applied' => ['nullable', 'integer', 'min:1'],
            'battery_level' => ['nullable', 'numeric', 'between:0,100'],
            'storage_free_mb' => ['nullable', 'integer', 'min:0'],
            'pending_events_count' => ['nullable', 'integer', 'min:0'],
            'network_state' => ['nullable', Rule::in(['ONLINE', 'OFFLINE', 'UNKNOWN'])],
            'last_error_category' => ['nullable', Rule::enum(FleetErrorCategory::class)],
            'last_error_code' => ['nullable', 'required_with:last_error_category', 'string', 'max:100'],
            'last_error_at' => ['nullable', 'required_with:last_error_category', 'date'],
            'device_time' => ['required', 'date'],
        ];
    }
}
