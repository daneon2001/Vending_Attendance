<?php

namespace Tests\Feature\Vending;

use App\Models\User;
use Database\Seeders\VendingPilotUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendingPilotUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_pilot_roles_idempotence_password_preservation_and_least_privilege(): void
    {
        foreach (['admin', 'operator', 'support', 'viewer'] as $role) {
            config(['employees.pilot.users.'.$role.'.password' => Str::random(32)]);
        }
        $this->seed(VendingPilotUsersSeeder::class);
        $before = User::pluck('password', 'id')->all();
        $this->seed(VendingPilotUsersSeeder::class);
        $this->assertTrue($before === User::pluck('password', 'id')->all(), 'Existing password hashes must remain unchanged.');
        $this->assertDatabaseCount('users', 4);
        foreach (['support', 'viewer'] as $role) {
            $user = User::where('email', 'pilot.'.$role.'@example.test')->firstOrFail();
            $this->assertTrue($user->hasPermission('employees', 'view'));
            $this->assertFalse($user->hasPermission('employees', 'import'));
            $this->assertFalse($user->hasPermission('employees', 'sync'));
            $this->assertFalse($user->hasPermission('settings', 'manage'));
        }
        $operator = User::where('email', 'pilot.operator@example.test')->firstOrFail();
        $this->assertTrue($operator->hasPermission('employees', 'import'));
        $this->assertFalse($operator->hasPermission('employees', 'manage'));
    }

    public function test_collision_and_missing_password_leave_real_users_untouched(): void
    {
        $real = User::factory()->create(['email' => 'pilot.admin@example.test']);
        $before = $real->fresh()->getRawOriginal();
        try {
            $this->seed(VendingPilotUsersSeeder::class);
            $this->fail('Expected real account collision.');
        } catch (\LogicException) {
            $this->assertTrue($before === $real->fresh()->getRawOriginal(), 'Existing account attributes must remain unchanged.');
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_missing_passwords_fail_atomically_without_creating_users(): void
    {
        foreach (['admin', 'operator', 'support', 'viewer'] as $role) {
            config(['employees.pilot.users.'.$role.'.password' => null]);
        }
        try {
            $this->seed(VendingPilotUsersSeeder::class);
            $this->fail('Missing pilot credentials must block provisioning.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('roles', 0);
        }
    }

    public function test_production_is_blocked_without_explicit_authorization(): void
    {
        $this->app->instance('env', 'production');
        config(['employees.pilot.allow_production' => false]);
        $this->expectException(\LogicException::class);
        $this->seed(VendingPilotUsersSeeder::class);
    }
}
