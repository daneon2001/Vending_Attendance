<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => (bool) $this->estatus,
            'status_label' => $this->estatus ? 'Activo' : 'Inactivo',
            'created_at' => optional($this->created_at)->toISOString(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => (bool) $role->is_system,
            ])->values()),
        ];
    }
}
