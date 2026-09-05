<?php

namespace App\Http\Requests\Vending;

use App\Enums\Vending\MobileReleaseChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceReleaseChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'release_channel' => ['required', Rule::enum(MobileReleaseChannel::class)],
            'release_group' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
