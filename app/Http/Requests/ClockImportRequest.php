<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClockImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ];
    }
}
