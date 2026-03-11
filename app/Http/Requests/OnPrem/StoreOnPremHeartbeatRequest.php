<?php

namespace App\Http\Requests\OnPrem;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOnPremHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_serial' => ['required', 'string', 'max:120'],
            'clock_id' => ['nullable', 'integer'],
            'unit_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'api_ok' => ['nullable', 'boolean'],
            'device_ok' => ['nullable', 'boolean'],
            'pending_count' => ['nullable', 'integer', 'min:0'],
            'last_event_at_utc' => ['nullable', 'date'],
            'last_event_at_local' => ['nullable', 'date'],
            'tz' => ['nullable', 'timezone'],
            'ip_local' => ['nullable', 'string', 'max:45'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'status_message' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'error' => 'VALIDATION_FAILED',
            'reason' => 'VALIDATION_FAILED',
            'message' => 'Request validation failed.',
            'details' => $validator->errors(),
        ], 422));
    }
}
