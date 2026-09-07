<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportPermissionsSeeder extends Seeder
{
    public const MATRIX = [
        'Vending Pilot Admin' => ['manage'],
        'Vending Pilot Operator' => ['view', 'report', 'comment', 'verify'],
        'Vending Pilot Support' => ['view', 'view_all', 'comment', 'assign', 'resolve', 'verify'],
        'Vending Pilot Viewer' => ['view', 'view_all'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $ids = [];
            foreach (config('permissions.modules.support.actions') as $action) {
                $permission = Permission::query()->firstOrCreate(['module' => 'support', 'action' => $action], ['name' => 'Soporte - '.$action, 'description' => 'Capacidad exclusiva del módulo de soporte.']);
                $ids[$action] = $permission->id;
            }
            // Only new support permissions; never create users/roles, reset passwords or detach existing permissions.
            foreach (self::MATRIX as $name => $actions) {
                $role = Role::query()->where('name', $name)->first();
                if ($role) {
                    $role->permissions()->syncWithoutDetaching(array_map(fn ($action) => $ids[$action], $actions));
                }
            }
        });
    }
}
