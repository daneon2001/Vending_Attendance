<?php

namespace Tests\Feature\FieldIdentity;

use App\Enums\Employees\EmployeeSource;
use App\Models\EmployeeDevice;
use App\Models\User;
use App\Services\FieldIdentity\BetaTesterPolicy;
use App\Services\FieldIdentity\DeviceIdentityService;
use App\Services\FieldIdentity\EnrollmentIdentity;
use App\Services\FieldIdentity\LocalBetaTesterRegistry;
use App\Services\FieldIdentity\RegisteredPhoneSource;
use App\Services\Support\FieldSupportActivityAccess;
use App\Services\Vending\MachineAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class BetaTesterPolicyTest extends VendingDeviceApiTestCase
{
    private LocalBetaTesterRegistry $registry;

    private array $people;

    private array $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(CarbonImmutable::parse('2026-09-14T12:00:00Z'));
        config(['internal_beta.testers_enabled' => true]);
        $this->registry = new class extends LocalBetaTesterRegistry
        {
            public array $document = ['version' => 1, 'testers' => []];

            protected function read(): array
            {
                return $this->document;
            }
        };
        $this->app->instance(LocalBetaTesterRegistry::class, $this->registry);
        foreach ([0, 1] as $i) {
            $this->people[$i] = $this->employee(['source' => EmployeeSource::MANUAL, 'employee_number' => 'BETA-FIXTURE-'.$i]);
            $this->users[$i] = User::factory()->create(['estatus' => true]);
            $this->users[$i]->employee()->associate($this->people[$i]);
            $this->users[$i]->save();
            $this->registry->document['testers'][] = [
                'user_id' => $this->users[$i]->id, 'employee_id' => $this->people[$i]->id,
                'employee_number' => $this->people[$i]->employee_number, 'employee_source' => 'MANUAL',
                'phone_e164' => '+52550000000'.($i + 1), // Synthetic fixture; never delivered.
                'enabled' => true, 'created_at' => '2026-09-14T10:00:00Z',
                'updated_at' => '2026-09-14T11:00:00Z', 'expires_at' => '2026-09-15T12:00:00Z',
                'approval_reference' => 'SYNTHETIC-TEST',
            ];
        }
    }

    public function test_server_beta_requires_environment_flag_and_non_debug_and_preserves_binding(): void
    {
        [$input, $key] = $this->active(0);
        $before = EmployeeDevice::first()->getRawOriginal();
        $this->app->detectEnvironment(fn () => 'beta');
        config(['internal_beta.enabled' => true, 'app.debug' => false]);
        $this->assertTrue(\App\Support\InternalBeta::enabled());
        $s = $this->service();
        $this->assertCount(1, $s->profile()['devices']);
        $this->sign($s, $s->challenge($input['device_uuid'], 'ACTOR'), $key, 'ACTOR');
        $after = EmployeeDevice::first()->getRawOriginal();
        foreach (['uuid', 'key_fingerprint', 'key_version', 'user_id', 'employee_id', 'activated_at'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        config(['internal_beta.enabled' => false]);
        $this->denied(fn () => $s->profile(), 503);
        config(['internal_beta.enabled' => true, 'app.debug' => true]);
        $this->denied(fn () => $s->profile(), 503);
        config(['app.debug' => false]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->denied(fn () => $s->profile(), 503);
        $this->denied(fn () => app(\App\Services\FieldIdentity\LocalOtpProvider::class)->deliver('+525500000000', '123456'), 503);
        $this->assertSame([], $this->registry->entries());
    }

    private function service(int $i = 0): DeviceIdentityService
    {
        $this->actingAs($this->users[$i]);

        return app(DeviceIdentityService::class);
    }

    private function denied(callable $action, int $status = 403): void
    {
        try {
            $action();
            $this->fail('Expected denial');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function registration(int $i): array
    {
        $s = $this->service($i);
        $uuid = (string) Str::uuid();
        $otp = $s->sendOtp($uuid);
        $s->verifyOtp($otp['otp_uuid'], $otp['local_code'], $uuid);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $input = ['device_uuid' => $uuid, 'operation_uuid' => (string) Str::uuid(), 'otp_uuid' => $otp['otp_uuid'],
            'public_key' => openssl_pkey_get_details($key)['key'], 'platform' => 'android',
            'platform_version' => 'fixture', 'app_version' => 'fixture', 'hardware_model' => 'Synthetic'];
        $s->register($input);

        return [$input, $key];
    }

    private function sign(DeviceIdentityService $s, array $challenge, $key, string $purpose): array
    {
        openssl_sign($challenge['message'], $signature, $key, OPENSSL_ALGO_SHA256);

        return $s->prove($challenge['challenge_uuid'], base64_encode($signature), $purpose);
    }

    private function active(int $i): array
    {
        [$input, $key] = $this->registration($i);
        $s = $this->service($i);
        $this->sign($s, $s->challenge($input['device_uuid']), $key, 'ENROLLMENT');

        return [$input, $key];
    }

    public function test_manual_allowlist_does_not_modify_global_resolver_or_employee_source(): void
    {
        $this->service();
        $this->assertSame($this->people[0]->id, app(EnrollmentIdentity::class)->resolve($this->users[0])->id);
        $this->assertNull(User::authenticatedEmployee());
        $this->assertSame(EmployeeSource::MANUAL, $this->people[0]->fresh()->source);
        config(['internal_beta.testers_enabled' => false]);
        $this->assertNull(app(EnrollmentIdentity::class)->resolve($this->users[0]));
    }

    public function test_disabled_expired_unlisted_and_wrong_user_deny(): void
    {
        $policy = app(BetaTesterPolicy::class);
        $this->assertFalse($policy->allows($this->users[1], $this->people[0]));
        $good = $this->registry->document;
        foreach ([['enabled' => false], ['expires_at' => '2026-09-14T12:00:00Z'],
            ['employee_number' => 'OTHER'], ['phone_e164' => 'invalid']] as $change) {
            $this->registry->document = $good;
            $this->registry->document['testers'][0] = array_replace($good['testers'][0], $change);
            $this->assertFalse($policy->allows($this->users[0], $this->people[0]));
        }
        $this->registry->document = ['version' => 1, 'testers' => []];
        $this->assertFalse($policy->allows($this->users[0], $this->people[0]));
    }

    public function test_registry_rejects_duplicates_and_never_reads_local_file_in_testing_or_production(): void
    {
        $this->assertSame([], (new LocalBetaTesterRegistry)->entries());
        $this->registry->document['testers'][] = $this->registry->document['testers'][0];
        $this->assertSame([], $this->registry->entries());
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame([], $this->registry->entries());
        $this->assertNull(app(EnrollmentIdentity::class)->resolve($this->users[0]));
        $this->service();
        $this->assertNull(app(RegisteredPhoneSource::class)->forEmployee($this->people[0]));
        $this->denied(fn () => $this->service()->sendOtp((string) Str::uuid()), 503);
    }

    public function test_phone_and_otp_are_owner_attempt_and_device_scoped(): void
    {
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        $a = $this->service()->sendOtp($ids[0]);
        $storedA = DB::table('field_device_otps')->where('user_id', $this->users[0]->id)->first();
        $b = $this->service(1)->sendOtp($ids[1]);
        $this->assertNotSame($a['otp_uuid'], $b['otp_uuid']);
        $this->assertNotSame($a['phone'], $b['phone']);
        $s = $this->service(1);
        $this->denied(fn () => $s->verifyOtp($a['otp_uuid'], $a['local_code'], $ids[0]), 422);
        $this->assertEquals($storedA, DB::table('field_device_otps')->where('user_id', $this->users[0]->id)->first());
        $this->denied(fn () => $s->verifyOtp($b['otp_uuid'], $b['local_code'], $ids[0]), 422);
        $this->assertTrue($s->verifyOtp($b['otp_uuid'], $b['local_code'], $ids[1])['verified']);
        $this->denied(fn () => $s->verifyOtp($b['otp_uuid'], $b['local_code'], $ids[1]), 422);
        $s = $this->service();
        $this->assertTrue($s->verifyOtp($a['otp_uuid'], $a['local_code'], $ids[0])['verified']);
        foreach (DB::table('field_device_otps')->get() as $row) {
            $this->assertStringNotContainsString('+52550000000', $row->phone);
        }
    }

    public function test_beta_requires_device_context_and_otp_expiry_or_phone_change_denies(): void
    {
        $s = $this->service();
        $this->denied(fn () => $s->sendOtp(), 422);
        $uuid = (string) Str::uuid();
        $otp = $s->sendOtp($uuid);
        $this->registry->document['testers'][0]['phone_e164'] = '+525500000009';
        $this->denied(fn () => $s->verifyOtp($otp['otp_uuid'], $otp['local_code'], $uuid), 422);
        $this->travel(5)->minutes();
        $this->denied(fn () => $s->verifyOtp($otp['otp_uuid'], $otp['local_code'], $uuid), 422);
    }

    public function test_two_employees_have_distinct_active_bindings_and_cross_keys_and_users_are_denied(): void
    {
        [$a, $ka] = $this->active(0);
        [$b, $kb] = $this->active(1);
        $this->assertNotSame($a['device_uuid'], $b['device_uuid']);
        $this->assertNotSame($a['public_key'], $b['public_key']);
        $this->assertSame(2, EmployeeDevice::where('status', 'ACTIVE')->count());
        $this->assertSame(2, EmployeeDevice::distinct()->count('key_fingerprint'));
        foreach ([[0, $a, $ka, $b, $kb], [1, $b, $kb, $a, $ka]] as [$i, $own, $ownKey, $other, $wrongKey]) {
            $s = $this->service($i);
            $this->assertCount(1, $s->profile()['devices']);
            $this->denied(fn () => $s->challenge($other['device_uuid'], 'ACTOR'));
            $this->denied(fn () => $s->register($other), 409);
            $challenge = $s->challenge($own['device_uuid'], 'ACTOR');
            $this->denied(fn () => $this->sign($s, $challenge, $wrongKey, 'ACTOR'));
            $challenge = $s->challenge($own['device_uuid'], 'ACTOR');
            $proof = $this->sign($s, $challenge, $ownKey, 'ACTOR');
            $this->assertTrue($proof['context']->deviceVerified);
            $this->assertFalse($proof['context']->phoneVerified);
            $this->denied(fn () => $this->sign($s, $challenge, $ownKey, 'ACTOR'));
        }
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('employee_machine_assignments', 0);
    }

    public function test_disabled_policy_blocks_access_without_mutating_active_binding(): void
    {
        $this->active(0);
        $before = EmployeeDevice::first()->getRawOriginal();
        $this->registry->document['testers'][0]['enabled'] = false;
        $this->denied(fn () => $this->service()->profile());
        $this->assertSame($before, EmployeeDevice::first()->getRawOriginal());
    }

    public function test_registration_cannot_substitute_device_after_otp_and_second_active_is_denied(): void
    {
        [$input, $key] = $this->active(0);
        $before = EmployeeDevice::first()->getRawOriginal();
        $this->travel(61)->seconds();
        $s = $this->service();
        $uuid = (string) Str::uuid();
        $otp = $s->sendOtp($uuid);
        $s->verifyOtp($otp['otp_uuid'], $otp['local_code'], $uuid);
        $another = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $new = array_replace($input, ['operation_uuid' => (string) Str::uuid(), 'device_uuid' => (string) Str::uuid(),
            'otp_uuid' => $otp['otp_uuid'], 'public_key' => openssl_pkey_get_details($another)['key']]);
        $this->denied(fn () => $s->register($new), 422);
        $new['device_uuid'] = $uuid;
        $this->denied(fn () => $s->register($new), 409);
        $this->assertDatabaseCount('employee_devices', 1);
        $this->assertSame($before, EmployeeDevice::first()->getRawOriginal());
    }

    public function test_expiry_does_not_revoke_active_identity_and_unauthorized_user_link_denies(): void
    {
        $this->active(0);
        $before = EmployeeDevice::first()->getRawOriginal();
        $this->travel(2)->days();
        $this->assertNull(app(EnrollmentIdentity::class)->resolve($this->users[0]));
        $this->assertSame($before, EmployeeDevice::first()->getRawOriginal());
        $this->travelBack();
        $this->users[0]->forceFill(['employee_id' => null])->save();
        $this->assertFalse(app(BetaTesterPolicy::class)->allows($this->users[0], $this->people[0]));
    }

    public function test_explicit_assignment_and_role_allow_only_own_demo_maintenance(): void
    {
        $this->active(0);
        $machine = $this->machine('VM-DEMO-001');
        $machine->forceFill(['source' => 'DEMO'])->save();
        $role = \App\Models\Role::create(['name' => 'Synthetic limited tester']);
        foreach (['view', 'resolve'] as $action) {
            $role->permissions()->attach(\App\Models\Permission::firstOrCreate(['module' => 'support', 'action' => $action], ['name' => 'support.'.$action]));
        }
        $this->users[0]->roles()->attach($role);
        $this->assignment($machine, $this->people[0], ['maintenance_allowed' => true, 'attendance_allowed' => false, 'enrollment_allowed' => false]);
        $access = app(FieldSupportActivityAccess::class);
        $access->permission($this->users[0], 'resolve');
        $access->machine($this->people[0], $machine, \App\Enums\Support\SupportActivityType::MAINTENANCE);
        $this->assertFalse(app(MachineAuthorizationService::class)->canAttend($this->people[0], $machine));
        $this->denied(fn () => $access->machine($this->people[1], $machine));
        $other = $this->machine('7');
        $other->forceFill(['source' => 'SYBI'])->save();
        $this->denied(fn () => $access->machine($this->people[0], $other));
        $this->denied(fn () => $access->machine($this->people[0], $machine, \App\Enums\Support\SupportActivityType::CONFIGURATION));
        $this->registry->document['testers'][0]['enabled'] = false;
        $this->assertSame(0, $access->notificationScope($this->users[0])->count());
    }

    public function test_beta_policy_grants_neither_attendance_nor_support_capabilities(): void
    {
        $this->active(0);
        $machine = $this->machine('VM-DEMO-001');
        $machine->forceFill(['source' => 'DEMO'])->save();
        $this->assertFalse(app(MachineAuthorizationService::class)->canAttend($this->people[0], $machine));
        $this->assertFalse(app(MachineAuthorizationService::class)->canMaintain($this->people[0], $machine));
        $access = app(FieldSupportActivityAccess::class);
        $this->assertSame($this->people[0]->id, $access->identity()[1]->id);
        $this->denied(fn () => $access->permission($this->users[0], 'resolve'));
        $this->denied(fn () => $access->machine($this->people[0], $machine));
    }
}
