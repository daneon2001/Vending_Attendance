<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'status' => ['required', 'in:0,1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name', ''));
        $code = $this->input('code');
        $code = is_string($code) ? Str::upper(trim($code)) : $code;

        $this->merge([
            'name' => $name,
            'code' => $code === '' ? null : $code,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $name = trim((string) $this->input('name', ''));
            $code = $this->input('code');

            if ($name !== '' && Company::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists()) {
                $validator->errors()->add('name', 'Ya existe una empresa con ese nombre.');
            }

            if (is_string($code) && $code !== '' && Company::query()->whereRaw('LOWER(code) = ?', [Str::lower($code)])->exists()) {
                $validator->errors()->add('code', 'Ya existe una empresa con esa clave.');
            }
        });
    }
}
