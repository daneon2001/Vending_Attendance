<?php

namespace App\Http\Requests\Vending;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMobileReleaseTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(['DEVICE', 'GROUP'])],
            'target_value' => [
                'required', 'string', 'max:100',
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
        ];
    }
}
