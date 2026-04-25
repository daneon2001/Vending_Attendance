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

        $allowedLocationIds = [];
        if ($this->relationLoaded('allowedLocations')) {
            $allowedLocationIds = $this->allowedLocations
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        return [
            'id' => (int) $this->id,
            'fortia_employee_id' => $this->fortia_employee_id !== null ? (string) $this->fortia_employee_id : null,
            'code' => $this->fortia_employee_id !== null ? (string) $this->fortia_employee_id : (string) $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'second_last_name' => $this->second_last_name,
            'full_name' => $fullName ?: null,
            'company_id' => $this->company_id ? (int) $this->company_id : null,
            'company_name' => $this->company_name,
            'base_location_id' => $this->base_location_id ? (int) $this->base_location_id : null,
            'base_location_name' => $this->unit?->name ?? $this->base_location_name,
            'unit_id' => $this->base_location_id ? (int) $this->base_location_id : null,
            'unit_name' => $this->unit?->name ?? $this->base_location_name,
            'rfc' => $this->rfc,
            'curp' => $this->curp,
            'status' => $this->normalizeStatus($this->status),
            'status_code' => $this->normalizeStatusCode($this->status),

            'check_scope' => $this->check_scope ? (string) $this->check_scope : null,
            'resolved_check_scope' => (string) ($this->resolved_check_scope ?? 'HOME_ONLY'),
            'can_check_all_branches' => (bool) ($this->can_check_all_branches ?? false),
            'allowed_location_ids' => $allowedLocationIds,

            'fingerprint_status' => (string) $this->fingerprint_status,
            'has_fingerprint' => (bool) $this->has_fingerprint,

            'has_face_enrollment' => (bool) ($this->has_face_enrollment ?? false),
            'face_status' => (string) ($this->face_status ?? 'none'),
            'face_enabled' => (bool) ($this->face_enabled ?? false),
            'face_samples_count' => (int) ($this->face_samples_count ?? 0),
            'face_template_version' => $this->face_template_version,
            'face_updated_at' => optional($this->face_updated_at)->toISOString(),
            'face_quality_score' => is_numeric($this->face_quality_score) ? (int) $this->face_quality_score : null,
            'face_meta' => is_array($this->face_meta) ? $this->face_meta : null,
            'face_sync_ready' => (bool) ($this->face_sync_ready ?? false),

            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }

    private function normalizeStatus(?string $status): string
    {
        return in_array(strtolower((string) $status), ['a', 'active'], true)
            ? 'ACTIVE'
            : 'INACTIVE';
    }

    private function normalizeStatusCode(?string $status): string
    {
        return in_array(strtolower((string) $status), ['a', 'active'], true)
            ? 'A'
            : 'B';
    }
}
