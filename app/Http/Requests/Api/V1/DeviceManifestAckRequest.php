<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Vending\ManifestAckStatus;
use App\Enums\Vending\ManifestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceManifestAckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'manifest_type' => ['required', Rule::enum(ManifestType::class)],
            'manifest_version' => ['required', 'integer', 'min:1'],
            'manifest_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
            'applied_at' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(ManifestAckStatus::class)],
            'error_code' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'error_message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
