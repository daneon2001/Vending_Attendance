<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeMachineAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->visibleEmployeeKey(),
                'name' => $this->employee->full_name ?: trim($this->employee->name.' '.$this->employee->last_name),
            ]),
            'machine' => $this->whenLoaded('vendingMachine', fn () => [
                'uuid' => $this->vendingMachine->uuid,
                'machine_code' => $this->vendingMachine->machine_code,
                'name' => $this->vendingMachine->name,
            ]),
            'assignment_type' => $this->assignment_type?->value ?? $this->assignment_type,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'permissions' => [
                'attendance' => (bool) $this->attendance_allowed,
                'enrollment' => (bool) $this->enrollment_allowed,
                'maintenance' => (bool) $this->maintenance_allowed,
            ],
            'status' => $this->status?->value ?? $this->status,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
