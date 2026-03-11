<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $hasRbacSchema = Schema::hasTable('roles')
            && Schema::hasTable('permissions')
            && Schema::hasTable('role_user')
            && Schema::hasTable('permission_role');

        if ($user && $hasRbacSchema) {
            $user->loadMissing('roles.permissions');
        }

        return [
            ...parent::share($request),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->fullUrl(),
            ],
            'auth' => [
                'user' => $user,
                'roles' => $hasRbacSchema
                    ? $user?->roles->map(fn ($role) => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'is_system' => $role->is_system,
                    ])->values() ?? []
                    : [],
                'permissions' => $hasRbacSchema
                    ? ($user?->permissionsMatrix() ?? [])
                    : [],
            ],
        ];
    }
}