<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'description' => $this->description,
            'created_at' => optional($this->created_at)->toISOString(),
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
