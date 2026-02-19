<?php

namespace App\Http\Requests\OnPrem;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

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
            'unit_id' => ['required', 'integer'],
            'clock_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'events' => ['required', 'array', 'min:1', 'max:'.$maxBatchSize],
            'events.*' => ['required', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $events = $this->input('events');
        if (! is_array($events) && is_array($this->input('punches'))) {
            $events = $this->input('punches');
        }

        if (! is_array($events)) {
            return;
        }

        $normalized = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                $normalized[] = $event;
                continue;
            }

            $normalized[] = [
                'local_event_id' => $event['local_event_id'] ?? null,
                'collaborator_id' => $event['collaborator_id'] ?? null,
                'punched_at_local' => $event['punched_at_local'] ?? ($event['event_time_local'] ?? null),
                'timezone' => $event['timezone'] ?? ($event['tz'] ?? null),
                'punched_at_utc' => $event['punched_at_utc'] ?? ($event['event_time_utc'] ?? null),
                'source' => $event['source'] ?? null,
                'type_inout' => $event['type_inout'] ?? null,
                'quality' => $event['quality'] ?? null,
                'meta' => $event['meta'] ?? null,
            ];
        }

        $this->merge([
            'events' => $normalized,
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        $failed = $validator->failed();
        $isBatchTooLarge = isset($failed['events']['Max']);

        throw new HttpResponseException(response()->json([
            'ok' => false,
            'error' => $isBatchTooLarge ? 'BATCH_TOO_LARGE' : 'VALIDATION_FAILED',
            'reason' => $isBatchTooLarge ? 'BATCH_TOO_LARGE' : 'VALIDATION_FAILED',
            'message' => $isBatchTooLarge ? 'Batch exceeds allowed size.' : 'Request validation failed.',
            'details' => $validator->errors(),
        ], 422));
    }
}
