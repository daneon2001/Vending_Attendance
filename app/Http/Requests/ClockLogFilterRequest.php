<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClockLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'level' => ['nullable', 'in:info,warning,error'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
