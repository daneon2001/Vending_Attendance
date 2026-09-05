<?php

namespace App\Http\Requests\Vending;

use App\Enums\Vending\MobilePlatform;
use App\Enums\Vending\MobileReleaseChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMobileReleasePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::enum(MobilePlatform::class)],
            'channel' => ['required', Rule::enum(MobileReleaseChannel::class)],
            'current_release_id' => ['nullable', 'integer', 'exists:mobile_releases,id'],
            'recommended_release_id' => ['nullable', 'integer', 'exists:mobile_releases,id'],
            'minimum_release_id' => ['nullable', 'integer', 'exists:mobile_releases,id'],
        ];
    }
}
