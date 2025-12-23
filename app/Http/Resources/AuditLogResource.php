<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray($request): array
    {
        $timezone = config('app.timezone', 'UTC');
        $createdAt = $this->created_at ? $this->created_at->copy() : null;

        return [
            'id' => $this->id,
            'event' => $this->event,
            'description' => $this->description,
            'created_at' => optional($createdAt)->toISOString(),
            'created_at_local' => optional($createdAt)
                ? $createdAt->copy()->setTimezone($timezone)->format('d/m/Y H:i:s')
                : null,
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user_name ?? optional($this->user)->name,
                'email' => $this->user_email ?? optional($this->user)->email,
            ],
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'has_metadata' => ! empty($this->metadata),
        ];
    }
}
