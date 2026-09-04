<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIONS = ['view', 'create', 'update', 'assign', 'geofence', 'manage'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        foreach (self::ACTIONS as $action) {
            DB::table('permissions')->updateOrInsert(
                ['module' => 'vending_machines', 'action' => $action],
                [
                    'name' => "vending_machines.{$action}",
                    'description' => "Permite {$action} en el dominio de máquinas vending",
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionIds = DB::table('permissions')->where('module', 'vending_machines')->pluck('id');
        $roleIds = DB::table('roles')
            ->whereIn(DB::raw('LOWER(name)'), ['administrador', 'admin', 'superadmin', 'super admin'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')->where('module', 'vending_machines')->pluck('id');
        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        }
        DB::table('permissions')->where('module', 'vending_machines')->delete();
    }
};
