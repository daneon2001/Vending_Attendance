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

        $now = now();

        DB::table('permissions')->updateOrInsert(
            [
                'module' => 'biometrics',
                'action' => 'fingerprints.delete',
            ],
            [
                'name' => 'biometrics.fingerprints.delete',
                'description' => 'Permite eliminar huellas por empleado',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('module', 'biometrics')
            ->where('action', 'fingerprints.delete')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn(DB::raw('LOWER(name)'), ['administrador', 'admin', 'superadmin', 'super admin'])
            ->pluck('id')
            ->all();

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('module', 'biometrics')
            ->where('action', 'fingerprints.delete')
            ->value('id');

        if ($permissionId && Schema::hasTable('permission_role')) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')
            ->where('module', 'biometrics')
            ->where('action', 'fingerprints.delete')
            ->delete();
    }
};
