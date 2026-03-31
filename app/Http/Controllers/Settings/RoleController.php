<?php

namespace App\Http\Controllers\Settings;

use App\Actions\SyncPermissionCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        SyncPermissionCatalog::run();

        $roles = Role::with(['permissions'])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => RoleResource::collection($roles),
        ]);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $role = Role::create($request->safe()->only(['name', 'description']));

        $this->syncPermissions($role, $request->validated('permissions', []));

        AuditLogger::log(
            'roles.created',
            $role,
            'Rol creado',
            [
                'permissions' => $request->validated('permissions', []),
            ]
        );

        return response()->json([
            'data' => new RoleResource($role->load(['permissions', 'users'])->loadCount('users')),
        ], 201);
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $requestedName = trim((string) $request->input('name', ''));
        $isRenamingSystemRole = $role->is_system
            && $request->has('name')
            && $requestedName !== ''
            && $requestedName !== $role->name;

        if ($isRenamingSystemRole) {
            throw ValidationException::withMessages([
                'name' => 'No puedes renombrar un rol del sistema.',
            ]);
        }

        $before = $role->only(['name', 'description']);
        $attributes = $request->safe()->only(['description']);

        if (! $role->is_system && $request->has('name')) {
            $attributes['name'] = $request->validated('name');
        }

        $role->fill($attributes)->save();

        $this->syncPermissions($role, $request->validated('permissions', []));

        AuditLogger::log(
            'roles.updated',
            $role,
            'Rol actualizado',
            [
                'before' => $before,
                'permissions' => $request->validated('permissions', []),
            ]
        );

        return response()->json([
            'data' => new RoleResource($role->load(['permissions', 'users'])->loadCount('users')),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json([
                'message' => 'No puedes eliminar un rol del sistema.',
            ], 422);
        }

        $role->permissions()->detach();
        $role->users()->detach();
        $role->delete();

        AuditLogger::log(
            'roles.deleted',
            null,
            'Rol eliminado',
            [
                'role_name' => $role->name,
            ]
        );

        return response()->json([
            'message' => 'Rol eliminado correctamente.',
        ]);
    }

    protected function syncPermissions(Role $role, array $definitions): void
    {
        $permissionIds = SyncPermissionCatalog::resolveIds($definitions);

        $role->permissions()->sync($permissionIds);
    }
}
