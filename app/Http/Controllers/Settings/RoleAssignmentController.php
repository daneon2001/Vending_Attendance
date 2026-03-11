<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleAssignmentController extends Controller
{
    public function store(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $role->users()->syncWithoutDetaching($validated['user_ids']);

        AuditLogger::log(
            'roles.users_assigned',
            $role,
            'Usuarios asignados al rol',
            [
                'user_ids' => $validated['user_ids'],
            ]
        );

        return response()->json([
            'data' => new RoleResource($role->load(['permissions', 'users'])->loadCount('users')),
        ]);
    }

    public function destroy(Role $role, User $user): JsonResponse
    {
        $role->users()->detach($user->id);

        AuditLogger::log(
            'roles.user_detached',
            $role,
            'Usuario removido del rol',
            [
                'user_id' => $user->id,
                'user_name' => $user->name,
            ]
        );

        return response()->json([
            'data' => new RoleResource($role->load(['permissions', 'users'])->loadCount('users')),
        ]);
    }
}
