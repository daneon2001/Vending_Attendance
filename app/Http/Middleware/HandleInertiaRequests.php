<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
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
