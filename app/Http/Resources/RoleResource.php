<?php

namespace App\Http\Resources;

use App\Actions\SyncPermissionCatalog;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray($request): array
    {
        $permissions = $this->whenLoaded('permissions', function () {
            return $this->permissions
                ->groupBy(fn ($permission) => SyncPermissionCatalog::normalizeModuleKey($permission->module))
                ->map(fn ($items) => $items->pluck('action')->unique()->values())
                ->toArray();
        }, []);

        $userCount = isset($this->users_count)
            ? (int) $this->users_count
            : ($this->relationLoaded('users') ? $this->users->count() : 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => (bool) $this->is_system,
            'user_count' => $userCount,
            'permissions' => $permissions,
            'users' => $this->whenLoaded('users', fn () => $this->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => (bool) $user->estatus,
                'status_label' => $user->estatus ? 'Activo' : 'Inactivo',
            ])->values()),
        ];
    }
}
