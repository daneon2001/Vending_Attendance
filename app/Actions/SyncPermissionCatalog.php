<?php

namespace App\Actions;

use App\Models\Permission;

class SyncPermissionCatalog
{
    /**
     * Sincroniza el catalogo de permisos declarado en config/permissions.php.
     *
     * @return array<string, array<string, int>>
     */
    public static function run(): array
    {
        $modules = config('permissions.modules', []);
        $standardActions = config('permissions.standard_actions', []);

        $permissionsMap = [];

        foreach ($modules as $moduleKey => $moduleMeta) {
            $actions = $moduleMeta['actions'] ?? $standardActions;
            $label = $moduleMeta['label'] ?? $moduleKey;

            foreach ($actions as $action) {
                $permission = Permission::updateOrCreate(
                    [
                        'module' => $moduleKey,
                        'action' => $action,
                    ],
                    [
                        'name' => ucfirst((string) $label).' - '.ucfirst((string) $action),
                        'description' => sprintf('Permite %s en el modulo %s', $action, $label),
                    ],
                );

                $permissionsMap[$moduleKey][$action] = $permission->id;
            }
        }

        return $permissionsMap;
    }
}
