<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray($request): array
    {
        $timezone = $this->auditTimezone();
        $createdAtUtc = $this->normalizeStoredDateTime($this->created_at);

        return [
            'id' => $this->id,
            'event' => $this->event,
            'action' => $this->action,
            'module' => $this->entity
                ?? (is_string($this->event) && str_contains($this->event, '.') ? explode('.', $this->event)[0] : null),
            'entity' => $this->entity ?? $this->auditable_type,
            'entity_id' => $this->entity_id ?? $this->auditable_id,
            'description' => $this->description,
            'reason' => $this->reason,
            'ip_address' => $this->ip_address,
            'created_at' => optional($createdAtUtc)->toISOString(),
            'created_at_local' => optional($createdAtUtc)
                ? $createdAtUtc->copy()->setTimezone($timezone)->format('d/m/Y H:i:s')
                : null,
            'request_id' => $this->request_id,
            'correlation_id' => $this->correlation_id,
            'user' => [
                'id' => $this->actor_user_id ?? $this->user_id,
                'name' => $this->user_name ?? optional($this->user)->name,
                'email' => $this->user_email ?? optional($this->user)->email,
                'type' => $this->actor_type ?? 'user',
                'identifier' => $this->actor_identifier,
            ],
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'has_metadata' => ! empty($this->metadata),
        ];
    }

    private function auditTimezone(): string
    {
        return (string) config('operations.timezone', config('app.timezone', 'America/Mexico_City'));
    }

    private function storageTimezone(): string
    {
        return (string) config('operations.storage_timezone', 'UTC');
    }

    private function normalizeStoredDateTime(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return Carbon::parse($value->format('Y-m-d H:i:s'), $this->storageTimezone());
        }

        return Carbon::parse((string) $value, $this->storageTimezone());
    }
}
