<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class VendingPilotAttendanceReadSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Pilot attendance access is restricted to local/testing.');
        }

        DB::transaction(function (): void {
            $role = Role::where('name', 'Vending Pilot Admin')->lockForUpdate()->first();
            $permission = Permission::where('module', 'asistencias')->where('action', 'view')->first();
            if (! $role || $role->description !== 'Managed pilot account role: admin' || ! $permission) {
                throw new LogicException('Existing managed pilot role and attendance permission are required.');
            }

            // Legacy attendance_logs only; does not grant access to vending event details.
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        });
    }
}
