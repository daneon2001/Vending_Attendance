<?php

namespace App\Http\Requests\Support;

use App\Enums\Support\SupportActivityType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Object, assignment and capability authorization remain mandatory in the service.
        return User::authenticatedEmployee() !== null;
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        $rules = match ($action) {
            'store' => [
                'client_operation_uuid' => ['required', 'uuid'],
                'vending_machine_id' => ['required', 'integer', 'min:1'],
                'employee_id' => ['required', 'integer', 'min:1'],
                'support_ticket_uuid' => ['nullable', 'uuid'],
                'activity_type' => ['required', Rule::enum(SupportActivityType::class)],
                'title' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000'],
                'scheduled_at' => ['nullable', 'date'],
            ],
            'start' => [
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'accuracy_m' => ['required', 'numeric', 'between:0,100000'],
                'captured_at' => ['required', 'date'],
            ],
            'cancel' => ['cancellation_reason' => ['required', 'string', 'max:1000']],
            default => [],
        };
        // Never accept identity, machine, state or policy overrides during execution.
        foreach (array_keys($this->all()) as $field) {
            if (! array_key_exists($field, $rules) && ! in_array($field, ['_token'], true)) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }
}
