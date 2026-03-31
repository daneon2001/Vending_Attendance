<?php

namespace App\Actions;

use App\Models\Permission;
use Illuminate\Support\Arr;

class SyncPermissionCatalog
{
    /**
     * @var array<string, string>
     */
    private const MODULE_ALIASES = [
        'locations' => 'units',
    ];

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

    /**
     * @param  array<string, array<int, string>|string>  $definitions
     * @return array<int, int>
     */
    public static function resolveIds(array $definitions): array
    {
        $permissionsMap = self::run();

        return collect($definitions)
            ->flatMap(function ($actions, string $module) use ($permissionsMap) {
                $modulePermissions = $permissionsMap[self::normalizeModuleKey($module)] ?? [];

                return collect(Arr::wrap($actions))
                    ->filter(fn (string $action) => isset($modulePermissions[$action]))
                    ->map(fn (string $action) => $modulePermissions[$action]);
            })
            ->unique()
            ->values()
            ->all();
    }

    public static function normalizeModuleKey(string $module): string
    {
        return self::MODULE_ALIASES[$module] ?? $module;
    }

    /**
     * @return array<int, string>
     */
    public static function compatibleModuleKeys(string $module): array
    {
        $normalized = self::normalizeModuleKey($module);
        $keys = [$normalized];

        foreach (self::MODULE_ALIASES as $legacy => $canonical) {
            if ($canonical === $normalized) {
                $keys[] = $legacy;
            }
        }

        return array_values(array_unique($keys));
    }
}
