<?php

namespace App\Http\Requests\OnPrem;

use Illuminate\Foundation\Http\FormRequest;
class StoreOnPremAttendancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBatchSize = max(1, (int) config('onprem.max_batch_size', 500));

        return [
            'device_serial' => ['required', 'string', 'max:120'],
            'clock_id' => ['nullable', 'integer'],
            'unit_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'timezone' => ['nullable', 'timezone'],
            'punches' => ['required', 'array', 'min:1', 'max:'.$maxBatchSize],
            'punches.*' => ['required', 'array'],
        ];
    }
}
