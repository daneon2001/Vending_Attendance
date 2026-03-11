<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = config('permissions.modules', []);
        $standardActions = config('permissions.standard_actions', []);

        $permissionsMap = [];

        foreach ($modules as $moduleKey => $moduleMeta) {
            $actions = $moduleMeta['actions'] ?? $standardActions;
            foreach ($actions as $action) {
                $permission = Permission::firstOrCreate(
                    [
                        'module' => $moduleKey,
                        'action' => $action,
                    ],
                    [
                        'name' => ucfirst($moduleMeta['label']).' - '.ucfirst($action),
                        'description' => sprintf('Permite %s en el módulo %s', $action, $moduleMeta['label']),
                    ],
                );

                $permissionsMap[$moduleKey][$action] = $permission->id;
            }
        }

        $rolesConfig = [
            'admin' => [
                'name' => 'Administrador',
                'description' => 'Acceso total al sistema',
                'is_system' => true,
                'permissions' => 'all',
            ],
            'capturista' => [
                'name' => 'Capturista',
                'description' => 'Gestiona catálogos, sin eliminar ni desactivar',
                'is_system' => true,
                'permissions' => [
                    'dashboard' => ['view'],
                    'employees' => ['view', 'create', 'update', 'sync'],
                    'attendance' => ['view', 'export'],
                    'asistencias' => ['view', 'export', 'edit'],
                    'clocks' => ['view', 'create', 'update', 'sync'],
                    'locations' => ['view', 'create', 'update'],
                ],
            ],
            'supervisor' => [
                'name' => 'Supervisor',
                'description' => 'Monitorea y exporta información, puede desactivar equipos',
                'is_system' => true,
                'permissions' => [
                    'dashboard' => ['view'],
                    'employees' => ['view', 'update', 'disable'],
                    'clocks' => ['view', 'update', 'disable'],
                    'attendance' => ['view', 'export'],
                    'asistencias' => ['view', 'export', 'edit'],
                    'locations' => ['view'],
                    'users' => ['view'],
                ],
            ],
            'consulta' => [
                'name' => 'Consulta',
                'description' => 'Solo lectura',
                'is_system' => true,
                'permissions' => [
                    'dashboard' => ['view'],
                    'employees' => ['view'],
                    'clocks' => ['view'],
                    'locations' => ['view'],
                    'attendance' => ['view'],
                    'asistencias' => ['view'],
                ],
            ],
        ];

        $roleInstances = [];

        foreach ($rolesConfig as $key => $roleConfig) {
            $role = Role::firstOrCreate(
                ['name' => $roleConfig['name']],
                [
                    'description' => $roleConfig['description'] ?? null,
                    'is_system' => $roleConfig['is_system'] ?? false,
                ],
            );

            $roleInstances[$key] = $role;

            $permissionIds = $this->resolvePermissionIds($roleConfig['permissions'], $permissionsMap);

            $role->permissions()->sync($permissionIds);
        }

        $preferredAdminEmails = array_values(array_unique(array_filter([
            config('permissions.super_admin_email'),
            'admin@gmail.com',
            'admin@asistencias.test',
        ])));

        $adminUser = User::query()
            ->whereIn('email', $preferredAdminEmails)
            ->first() ?? User::first();

        if ($adminUser && isset($roleInstances['admin'])) {
            $adminUser->roles()->syncWithoutDetaching([$roleInstances['admin']->id]);
        }
    }

    private function resolvePermissionIds($definition, array $permissionsMap): array
    {
        if ($definition === 'all') {
            return collect($permissionsMap)
                ->flatten()
                ->unique()
                ->values()
                ->all();
        }

        $ids = [];

        foreach ($definition as $module => $actions) {
            $modulePermissions = $permissionsMap[$module] ?? [];
            $actions = Arr::wrap($actions);

            foreach ($actions as $action) {
                if ($action === 'manage' && isset($modulePermissions['manage'])) {
                    $ids[] = $modulePermissions['manage'];
                    continue;
                }

                if (isset($modulePermissions[$action])) {
                    $ids[] = $modulePermissions[$action];
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
