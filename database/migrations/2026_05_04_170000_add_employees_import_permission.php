<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            [
                'module' => 'employees',
                'action' => 'import',
            ],
            [
                'name' => 'Catalogo de empleados - Import',
                'description' => 'Permite importar empleados desde Excel',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('module', 'employees')
            ->where('action', 'import')
            ->value('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', ['Administrador', 'administrador', 'admin'])
            ->pluck('id');

        if (! $permissionId || $roleIds->isEmpty()) {
            return;
        }

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('module', 'employees')
            ->where('action', 'import')
            ->value('id');

        if ($permissionId && Schema::hasTable('permission_role')) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')
            ->where('module', 'employees')
            ->where('action', 'import')
            ->delete();
    }
};
