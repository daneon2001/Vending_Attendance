<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'company_id' => ['nullable', 'exists:companies,id'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'clock_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'firmware_version' => ['nullable', 'string', 'max:120'],
            'ip_address' => ['nullable', 'ip'],
            'type_inout' => ['nullable', 'string', 'max:50'],
            'status' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'between:0,1'],
            'last_heartbeat_at' => ['nullable', 'date'],
            'last_status_message' => ['nullable', 'string', 'max:255'],
            'monitoring_status' => ['nullable', 'in:online,offline,warning'],
            'program_status' => ['nullable', 'string', 'max:30'],
            'onprem_shared_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
