<?php

namespace App\Http\Requests\Vending;

use App\Enums\Vending\MobilePlatform;
use App\Enums\Vending\MobileReleaseChannel;
use App\Enums\Vending\MobileReleaseStatus;
use App\Enums\Vending\MobileReleaseTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMobileReleaseRequest extends FormRequest
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
            'target_type' => ['required', Rule::enum(MobileReleaseTargetType::class)],
            'target_value' => [
                'nullable', 'required_unless:target_type,CHANNEL', 'string', 'max:100',
                Rule::when($this->input('target_type') === 'DEVICE', Rule::exists('devices', 'uuid')->whereNotNull('vending_machine_id')),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->input('target_type') === 'DEVICE'
                        && ! \Illuminate\Support\Str::isUuid((string) $value)) {
                        $fail('El target DEVICE debe ser un UUID válido.');
                    }
                    if ($this->input('target_type') === 'GROUP'
                        && preg_match('/^[A-Za-z0-9._-]+$/', (string) $value) !== 1) {
                        $fail('El grupo sólo admite letras, números, punto, guion y guion bajo.');
                    }
                },
            ],
            'version' => [
                'required', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/',
                Rule::unique('mobile_releases', 'version')->where(fn ($query) => $query
                    ->where('platform', $this->input('platform'))
                    ->where('channel', $this->input('channel'))
                    ->where('build_number', $this->input('build_number'))),
            ],
            'build_number' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(MobileReleaseStatus::class)],
            'minimum_os' => ['nullable', 'string', 'max:80'],
            'artifact_url' => [
                'nullable', 'required_if:status,PUBLISHED', 'url', 'starts_with:https://', 'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $parts = is_string($value) ? parse_url($value) : false;
                    if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
                        $fail('La URL no puede contener credenciales ni tokens en query string.');
                    }
                },
            ],
            'artifact_sha256' => ['nullable', 'required_if:status,PUBLISHED', 'regex:/^[a-fA-F0-9]{64}$/'],
            'mandatory' => ['required', 'boolean'],
            'rollout_percentage' => ['required', Rule::in([0, 1, 5, 25, 100])],
            'released_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
