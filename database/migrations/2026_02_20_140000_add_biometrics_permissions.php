<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        $permissions = [
            [
                'module' => 'biometrics',
                'action' => 'fingerprints.read',
                'name' => 'biometrics.fingerprints.read',
                'description' => 'Permite consultar metadatos de huellas por empleado',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'module' => 'biometrics',
                'action' => 'templates.read',
                'name' => 'biometrics.templates.read',
                'description' => 'Permite consultar plantillas biométricas (alto riesgo)',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                [
                    'module' => $permission['module'],
                    'action' => $permission['action'],
                ],
                $permission,
            );
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $fingerprintsPermissionId = DB::table('permissions')
            ->where('module', 'biometrics')
            ->where('action', 'fingerprints.read')
            ->value('id');

        $templatesPermissionId = DB::table('permissions')
            ->where('module', 'biometrics')
            ->where('action', 'templates.read')
            ->value('id');

        $adminRoleIds = DB::table('roles')
            ->whereIn(DB::raw('LOWER(name)'), ['administrador', 'admin'])
            ->pluck('id')
            ->all();

        foreach ($adminRoleIds as $roleId) {
            if ($fingerprintsPermissionId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'role_id' => $roleId,
                        'permission_id' => $fingerprintsPermissionId,
                    ],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }

        $superAdminRoleIds = DB::table('roles')
            ->whereIn(DB::raw('LOWER(name)'), ['superadmin', 'super admin'])
            ->pluck('id')
            ->all();

        foreach ($superAdminRoleIds as $roleId) {
            if ($templatesPermissionId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'role_id' => $roleId,
                        'permission_id' => $templatesPermissionId,
                    ],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('module', 'biometrics')
            ->pluck('id')
            ->all();

        if ($permissionIds !== [] && Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->where('module', 'biometrics')->delete();
    }
};
