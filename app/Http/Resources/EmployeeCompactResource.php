<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeCompactResource extends JsonResource
{
    public function toArray($request): array
    {
        $fullName = $this->full_name;
        if (! $fullName) {
            $fullName = trim(implode(' ', array_filter([
                $this->name,
                $this->last_name,
                $this->second_last_name,
            ])));
        }

        return [
            'id' => (int) $this->id,
            'code' => $this->fortia_employee_id !== null ? (string) $this->fortia_employee_id : (string) $this->id,
            'full_name' => $fullName ?: null,
            'unit_id' => $this->base_location_id ? (int) $this->base_location_id : null,
            'unit_name' => $this->unit?->name ?? $this->base_location_name,
            'status' => $this->normalizeStatus($this->status),
            'fingerprint_status' => (bool) $this->has_fingerprint,
            'has_fingerprint' => (bool) $this->has_fingerprint,
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }

    private function normalizeStatus(?string $status): string
    {
        return in_array(strtolower((string) $status), ['a', 'active'], true)
            ? 'ACTIVE'
            : 'INACTIVE';
    }
}
