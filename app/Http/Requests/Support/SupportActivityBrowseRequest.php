<?php

namespace App\Http\Requests\Support;

use App\Enums\Support\SupportActivityStatus;
use App\Enums\Support\SupportActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportActivityBrowseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->estatus && $this->user()->hasPermission('support', 'view');
    }

    public function rules(): array
    {
        $options = $this->route()->getActionMethod() === 'options';

        return [
            'search' => ['nullable', 'string', 'max:160'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['nullable', Rule::enum(SupportActivityStatus::class)],
            'activity_type' => [Rule::requiredIf($options && $this->input('purpose') === 'create' && $this->input('kind') === 'employees'), 'nullable', Rule::enum(SupportActivityType::class)],
            'vending_machine_id' => [Rule::requiredIf($options && ($this->input('kind') === 'tickets' || ($this->input('kind') === 'employees' && $this->input('purpose') === 'create'))), 'nullable', 'integer', 'min:1'],
            'employee_id' => ['nullable', 'integer', 'min:1'], 'ticket_uuid' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'has_ticket' => ['nullable', Rule::in(['yes', 'no'])],
            'purpose' => ['nullable', Rule::in(['filter', 'create'])],
            'kind' => [Rule::requiredIf($options), 'nullable', Rule::in(['employees', 'machines', 'tickets'])],
        ];
    }
}
