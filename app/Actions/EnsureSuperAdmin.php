<?php

namespace App\Actions;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Config;

class EnsureSuperAdmin
{
    /**
     * Garantiza que el usuario Administrador tenga todos los permisos y rol asignado.
     *
     * @return array{user:?User,role:?Role,permission_count:int}
     */
    public static function run(?string $email = null): array
    {
        $email = $email ?? Config::get('permissions.super_admin_email', env('ADMIN_EMAIL', 'admin@asistencias.test'));

        $user = $email
            ? User::where('email', $email)->first()
            : null;

        if (! $user) {
            $user = User::find(1);
        }

        if (! $user) {
            return ['user' => null, 'role' => null, 'permission_count' => 0];
        }

        $role = Role::firstOrCreate(
            ['name' => 'Administrador'],
            [
                'description' => 'Acceso total al sistema',
                'is_system' => true,
            ],
        );

        $permissionIds = Permission::pluck('id')->all();
        $role->permissions()->sync($permissionIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return [
            'user' => $user,
            'role' => $role,
            'permission_count' => count($permissionIds),
        ];
    }
}
