<?php

namespace App\Http\Requests\Vending;

use Illuminate\Validation\Rule;

class UpdateVendingMachineRequest extends StoreVendingMachineRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $machine = $this->route('vendingMachine');
        $rules['sybi_id'] = ['nullable', 'string', 'max:255', Rule::unique('vending_machines', 'sybi_id')->ignore($machine?->getKey())];
        $rules['machine_code'] = ['required', 'string', 'max:100', Rule::unique('vending_machines', 'machine_code')->ignore($machine?->getKey())];

        return $rules;
    }
}
