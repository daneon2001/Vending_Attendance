<?php

namespace Tests\Feature\FieldIdentity;

use App\Models\EmployeeDevice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class DeviceAdminTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutVite();
    }

    private function account(string $role, array $permissions = []): User
    {
        $user = User::factory()->create(['estatus' => true]);
        $record = Role::create(['name' => $role]);
        foreach ($permissions as [$module, $action]) {
            $permission = Permission::firstOrCreate(compact('module', 'action'), ['name' => $module.'.'.$action]);
            $record->permissions()->attach($permission);
        }
        $user->roles()->attach($record);

        return $user;
    }

    private function device(string $status = 'PENDING'): EmployeeDevice
    {
        $owner = User::factory()->create();
        $employee = $this->employee(['employee_number' => (string) random_int(100000, 999999)]);
        $owner->forceFill(['employee_id' => $employee->id])->save();

        return EmployeeDevice::unguarded(fn () => EmployeeDevice::create([
            'uuid' => (string) Str::uuid(), 'operation_uuid' => (string) Str::uuid(),
            'user_id' => $owner->id, 'employee_id' => $employee->id,
            'active_employee_id' => $status === 'ACTIVE' ? $employee->id : null,
            'public_key' => 'synthetic-public-key', 'key_fingerprint' => hash('sha256', Str::uuid()),
            'request_hash' => hash('sha256', Str::uuid()), 'platform' => 'android', 'platform_version' => '15',
            'app_version' => '1', 'hardware_model' => 'Synthetic test device', 'status' => $status,
            'verified_phone' => '+52'.random_int(1000000000, 9999999999),
            'phone_verification_method' => 'LOCAL_SIMULATED', 'phone_verified_at' => now(),
            'verified_at' => $status === 'ACTIVE' ? now() : null,
        ]));
    }

    public function test_admin_global_counts_and_masked_listing_are_real_and_read_only(): void
    {
        $admin = $this->account('Vending Pilot Admin', [['employee_device', 'manage']]);
        $device = $this->device('ACTIVE');
        foreach (['PENDING', 'REVOKED', 'REPLACED'] as $state) {
            $this->device($state);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->actingAs($admin)->get('/administration/identity')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('FieldIdentity/Index')->where('counts', ['ACTIVE' => 1, 'PENDING' => 1, 'REVOKED' => 1, 'REPLACED' => 1]));
        $response = $this->get('/administration/identity?tab=devices')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('devices.data', 4)->where('canManage', true)->where('devices.data.0.phoneVerified', false)
            ->missing('devices.data.0.public_key')->missing('devices.data.0.employee_id'));
        $this->assertStringNotContainsString($device->verified_phone, $response->getContent());
        $this->assertStringNotContainsString('synthetic-public-key', $response->getContent());
        $writes = array_filter($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|alter|drop)\b/i', $sql));
        $this->assertSame([], array_values($writes));
    }

    public function test_support_operator_viewer_and_settings_are_denied_direct_global_access(): void
    {
        $device = $this->device();
        foreach (['Support', 'Operator', 'Viewer', 'Settings'] as $role) {
            $user = $this->account('Vending Pilot '.$role, [['support', 'manage'], ['settings', 'manage']]);
            $this->actingAs($user)->get('/administration/identity')->assertForbidden();
            $this->post('/administration/identity/'.$device->uuid.'/revoke', ['confirm' => true])->assertForbidden();
        }
        $this->assertSame('PENDING', $device->fresh()->status);
        $this->assertDatabaseCount('field_device_audit_events', 0);
    }

    public function test_view_only_inactive_and_production_cannot_revoke(): void
    {
        $device = $this->device();
        $viewer = $this->account('Identity reader', [['employee_device', 'view']]);
        $this->actingAs($viewer)->get('/administration/identity')->assertOk();
        $this->post('/administration/identity/'.$device->uuid.'/revoke', ['confirm' => true])->assertForbidden();
        $admin = $this->account('Vending Pilot Admin', [['employee_device', 'manage']]);
        $this->actingAs($admin);
        $admin->update(['estatus' => false]);
        $this->get('/administration/identity')->assertForbidden();
        $this->app->instance('env', 'production');
        $this->get('/administration/identity')->assertStatus(503);
        $this->assertSame('PENDING', $device->fresh()->status);
    }

    public function test_admin_revocation_requires_confirmation_preserves_identity_and_is_idempotent(): void
    {
        $device = $this->device('ACTIVE');
        $admin = $this->account('Vending Pilot Admin', [['employee_device', 'manage']]);
        DB::table('field_device_challenges')->insert(['uuid' => (string) Str::uuid(), 'employee_device_id' => $device->id,
            'purpose' => 'ACTOR', 'message' => 'synthetic', 'expires_at' => now()->addMinute(), 'created_at' => now()]);
        $path = '/administration/identity/'.$device->uuid.'/revoke';
        $this->actingAs($admin)->postJson($path)->assertUnprocessable();
        $this->assertSame('ACTIVE', $device->fresh()->status);
        $this->post($path, ['confirm' => true, 'employee_id' => 999, 'status' => 'ACTIVE'])->assertRedirect();
        $this->post($path, ['confirm' => true])->assertRedirect();
        $after = $device->fresh();
        $this->assertSame('REVOKED', $after->status);
        $this->assertNull($after->active_employee_id);
        foreach (['employee_id', 'user_id', 'uuid', 'public_key', 'key_fingerprint'] as $key) {
            $this->assertSame($device->$key, $after->$key);
        }
        $this->assertDatabaseCount('field_device_audit_events', 1);
        $this->assertDatabaseHas('field_device_audit_events', ['user_id' => $admin->id, 'employee_id' => $device->employee_id, 'event' => 'ADMIN_DEVICE_REVOKED']);
        $this->assertNotNull(DB::table('field_device_challenges')->value('consumed_at'));
        foreach (['attendance_logs', 'vending_attendance_events', 'vending_support_activities', 'employee_machine_assignments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_enrollments_acknowledge_legacy_without_creating_templates_or_users(): void
    {
        $this->employee(['has_face_enrollment' => false]);
        $this->employee(['has_face_enrollment' => true]);
        $admin = $this->account('Vending Pilot Admin', [['employee_device', 'view']]);
        $this->actingAs($admin)->get('/administration/identity?tab=enrollments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('employees.data', 2)->where('employees.data.0.legacy_enrollment', false)->where('employees.data.1.legacy_enrollment', true));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employee_devices', 0);
    }
}
