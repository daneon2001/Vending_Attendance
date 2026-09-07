<?php

namespace Database\Seeders;

use App\Actions\SyncPermissionCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class VendingPilotUsersSeeder extends Seeder
{
    public const ROLE_PREFIX = 'Vending Pilot ';

    public const PERMISSIONS = [
        'admin' => ['employees' => ['view', 'sync', 'import', 'manage'], 'vending_machines' => ['view', 'create', 'update', 'assign', 'geofence', 'manage']],
        'operator' => ['employees' => ['view', 'sync', 'import'], 'vending_machines' => ['view', 'assign']],
        'support' => ['employees' => ['view'], 'vending_machines' => ['view']],
        'viewer' => ['employees' => ['view'], 'vending_machines' => ['view']],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) && config('employees.pilot.allow_production') !== true) {
            throw new LogicException('Pilot accounts require an explicitly authorized environment.');
        }
        DB::transaction(function (): void {
            // Validate every identity before making any changes; never claim an existing real account.
            foreach (self::PERMISSIONS as $key => $permissions) {
                $definition = config('employees.pilot.users.'.$key);
                $user = User::where('email', $definition['email'])->first();
                $roleName = self::ROLE_PREFIX.ucfirst($key);
                if ($user && (! $user->roles()->where('name', $roleName)->exists() || $user->name !== $definition['name'])) {
                    throw new LogicException('Pilot account collision; no existing account was modified.');
                }
                $password = $definition['password'] ?? null;
                if (! $user && (! is_string($password) || strlen($password) < 12)) {
                    throw new LogicException('Configure all missing VENDING_PILOT_*_PASSWORD variables with at least 12 characters.');
                }
                $existingRole = Role::where('name', $roleName)->first();
                if ($existingRole && $existingRole->description !== 'Managed pilot account role: '.$key) {
                    throw new LogicException('Pilot role collision; existing role was not changed.');
                }
            }
            foreach (self::PERMISSIONS as $key => $permissions) {
                $definition = config('employees.pilot.users.'.$key);
                $role = Role::firstOrCreate(['name' => self::ROLE_PREFIX.ucfirst($key)], [
                    'description' => 'Managed pilot account role: '.$key, 'is_system' => false,
                ]);
                $role->permissions()->syncWithoutDetaching(SyncPermissionCatalog::resolveIds($permissions));
                $user = User::firstOrCreate(['email' => $definition['email']], [
                    'name' => $definition['name'], 'password' => $definition['password'], 'estatus' => true,
                ]);
                if ($user->wasRecentlyCreated) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        });
        $this->command?->info('Pilot accounts provisioned idempotently.');
    }
}
