<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['array'],
            'permissions.*.*' => ['string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('name')) {
            $payload['name'] = trim((string) $this->input('name', ''));
        }

        if ($this->has('description')) {
            $description = $this->input('description');
            $payload['description'] = is_string($description) ? trim($description) : $description;
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
