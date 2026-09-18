<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\VendingPilotAttendanceReadSeeder;
use Database\Seeders\VendingPilotUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PilotAttendanceReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        foreach (['admin', 'operator', 'support', 'viewer'] as $key) {
            config(['employees.pilot.users.'.$key.'.password' => 'synthetic-test-password']);
        }
        $this->seed(VendingPilotUsersSeeder::class);
    }

    public function test_new_pilot_can_read_legacy_attendance_but_cannot_modify_export_or_administer(): void
    {
        $user = User::whereHas('roles', fn ($q) => $q->where('name', 'Vending Pilot Admin'))->firstOrFail();
        $this->assertTrue($user->hasPermission('asistencias', 'view'));
        foreach ([['asistencias', 'edit'], ['asistencias', 'export'], ['settings', 'manage']] as [$module, $action]) {
            $this->assertFalse($user->hasPermission($module, $action));
        }
        // This page reads attendance_logs, not vending_attendance_events (including Build 12 STORED).
        $this->actingAs($user)->get('/admin/asistencias')->assertOk();
        $this->get('/attendance-cards')->assertOk();
        $this->get('/admin/asistencias/export')->assertForbidden();
        $this->get('/admin/asistencias/export-checks')->assertForbidden();
        $this->post('/admin/asistencias/adjustments', [])->assertForbidden();
        $this->patch('/admin/asistencias/1/annul', [])->assertForbidden();
        $this->get('/settings')->assertForbidden();
    }

    public function test_existing_role_upgrade_is_additive_idempotent_and_preserves_other_roles_and_users(): void
    {
        $role = Role::where('name', 'Vending Pilot Admin')->firstOrFail();
        $view = Permission::where('module', 'asistencias')->where('action', 'view')->firstOrFail();
        $role->permissions()->detach($view->id); // Simulate the pre-TA-0C fixture, only in memory.
        $extra = Permission::where('module', 'support')->where('action', 'view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$extra->id]);
        $demo = Role::create(['name' => 'Vending Demo Admin']);
        $demo->permissions()->attach($extra->id);
        $before = DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $users = DB::table('users')->orderBy('id')->get()->toJson();
        $bindings = DB::table('role_user')->orderBy('role_id')->orderBy('user_id')->get()->toJson();
        $this->seed(VendingPilotAttendanceReadSeeder::class);
        $once = DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $this->seed(VendingPilotAttendanceReadSeeder::class);
        $this->assertSame($once, DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $remaining = DB::table('permission_role')->where(fn ($q) => $q->where('role_id', '!=', $role->id)->orWhere('permission_id', '!=', $view->id))->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $this->assertSame($before, $remaining);
        $this->assertSame(1, DB::table('permission_role')->where('role_id', $role->id)->where('permission_id', $view->id)->count());
        $this->assertTrue($users === DB::table('users')->orderBy('id')->get()->toJson(), 'Users remain unchanged.');
        $this->assertSame($bindings, DB::table('role_user')->orderBy('role_id')->orderBy('user_id')->get()->toJson());
        $user = User::whereHas('roles', fn ($q) => $q->whereKey($role->id))->firstOrFail();
        $this->assertFalse($user->hasPermission('settings', 'manage'));
        $this->actingAs($user)->get('/admin/asistencias')->assertOk();
    }

    public function test_targeted_upgrade_rejects_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\LogicException::class);
        $this->seed(VendingPilotAttendanceReadSeeder::class);
    }
}
