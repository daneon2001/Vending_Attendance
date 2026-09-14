<?php

namespace Tests\Feature\FieldIdentity;

use App\Enums\Employees\EmployeeSource;
use App\Models\Employee;
use App\Models\User;
use App\Services\FieldIdentity\DeviceIdentityService;
use App\Services\FieldIdentity\EnrollmentIdentity;
use App\Services\FieldIdentity\FieldMobileSession;
use App\Services\FieldIdentity\LocalDemoPhone;
use App\Services\FieldIdentity\RegisteredPhoneSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class FieldMobileUxTest extends VendingDeviceApiTestCase
{
    private User $person;

    private Employee $labor;

    // Generated in memory; never read or persist any configured phone in source.
    private string $fixturePhone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePhone = '+52'.random_int(1000000000, 9999999999);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(18, 0));
        $this->labor = Employee::unguarded(fn () => $this->employee([
            'id' => 5, 'employee_number' => '990001005', 'source' => EmployeeSource::DEMO,
            'full_name' => 'Synthetic demo employee', 'status' => 'A',
        ]));
        $this->person = User::factory()->create(['id' => 4, 'email' => 'pilot.support@example.test', 'estatus' => true]);
        $this->person->forceFill(['employee_id' => 5])->save();
        $phone = $this->getMockBuilder(LocalDemoPhone::class)->onlyMethods(['value'])->getMock();
        $phone->method('value')->willReturn($this->fixturePhone);
        $this->app->instance(LocalDemoPhone::class, $phone);
        Log::spy();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function login(): string
    {
        return $this->postJson('https://field.test/api/v1/field-mobile/session', [
            'email' => $this->person->email, 'password' => 'password',
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('token');
    }

    private function identity(string $action, array $body = [])
    {
        return $this->postJson('https://field.test/api/v1/field-mobile/'.$action, $body);
    }

    public function test_exact_demo_exception_preserves_global_resolver_and_data(): void
    {
        $this->actingAs($this->person);
        $this->assertSame(5, app(EnrollmentIdentity::class)->resolve($this->person)->id);
        $this->assertNull(User::authenticatedEmployee());
        $before = json_encode(DB::table('employees')->get());
        $profile = app(DeviceIdentityService::class)->profile();
        $this->assertFalse($profile['phoneVerified']);
        $this->assertSame(\App\Services\FieldIdentity\MexicanPhone::masked($this->fixturePhone), $profile['phone']);
        $this->assertSame($before, json_encode(DB::table('employees')->get()));
        foreach (['vending_attendance_events', 'attendance_logs', 'vending_support_activities', 'employee_devices', 'employee_machine_assignments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertFalse($this->person->hasPermission('support', 'report'));
    }

    public function test_other_demo_identity_source_or_inactive_state_is_rejected(): void
    {
        $resolver = app(EnrollmentIdentity::class);
        foreach ([['employee_number' => 'OTHER'], ['source' => EmployeeSource::MANUAL], ['status' => 'B']] as $change) {
            $original = $this->labor->getAttributes();
            $this->labor->forceFill($change)->save();
            $this->assertNull($resolver->resolve($this->person));
            $this->labor->forceFill($original)->save();
        }
        $this->person->update(['estatus' => false]);
        $this->assertNull($resolver->resolve($this->person));
        $this->person->update(['estatus' => true, 'email' => 'another@example.test']);
        $this->assertNull($resolver->resolve($this->person));
    }

    public function test_testing_and_production_never_read_the_local_phone_file(): void
    {
        $this->assertNull((new LocalDemoPhone)->value());
        $this->app->instance('env', 'production');
        $this->assertNull((new LocalDemoPhone)->value());
        $this->assertNull(app(EnrollmentIdentity::class)->resolve($this->person));
        $this->actingAs($this->person);
        $this->assertNull((new RegisteredPhoneSource)->forEmployee($this->labor));
    }

    public function test_forwarded_headers_cannot_turn_http_into_human_https(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'Forwarded' => 'proto=https'])
            ->postJson('http://field.test/api/v1/field-mobile/session', [])
            ->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_http_production_browser_origin_and_cookies_are_denied_without_tokens(): void
    {
        $body = ['email' => $this->person->email, 'password' => 'password'];
        $this->postJson('http://field.test/api/v1/field-mobile/session', $body)->assertForbidden();
        $this->withHeaders(['Origin' => 'https://localhost'])->postJson('https://field.test/api/v1/field-mobile/session', $body)->assertForbidden();
        $this->flushHeaders();
        $this->withHeaders(['Cookie' => 'laravel_session=synthetic'])->postJson('https://field.test/api/v1/field-mobile/session', $body)->assertForbidden();
        $this->flushHeaders();
        $this->app->instance('env', 'production');
        $this->postJson('https://field.test/api/v1/field-mobile/session', $body)->assertStatus(503);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expiring_human_token_cannot_authenticate_other_sanctum_apis(): void
    {
        $plain = $this->login();
        $stored = PersonalAccessToken::first();
        $this->assertNotSame($plain, $stored->token);
        $this->assertSame(['field-device:enroll'], $stored->abilities);
        $this->assertNotNull($stored->expires_at);
        $this->assertNull(PersonalAccessToken::findToken($plain));
        $this->assertSame(4, app(FieldMobileSession::class)->authenticate($plain)->id);
        $this->withToken($plain);
        $this->identity('profile')->assertOk()->assertJsonPath('phoneVerified', false)
            ->assertJsonPath('phone_verification_method', 'LOCAL_SIMULATED')->assertJsonPath('phone', \App\Services\FieldIdentity\MexicanPhone::masked($this->fixturePhone));
        $this->assertDatabaseCount('field_device_audit_events', 0);
        $this->assertDatabaseCount('field_device_otps', 0);
    }

    public function test_wrong_password_and_unknown_account_return_same_generic_error(): void
    {
        $known = $this->postJson('https://field.test/api/v1/field-mobile/session', ['email' => $this->person->email, 'password' => 'wrong'])->assertUnauthorized();
        $unknown = $this->postJson('https://field.test/api/v1/field-mobile/session', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertUnauthorized();
        $this->assertSame($known->json(), $unknown->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_attempt_limit(): void
    {
        foreach (range(1, 5) as $unused) {
            $this->postJson('https://field.test/api/v1/field-mobile/session', ['email' => $this->person->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('https://field.test/api/v1/field-mobile/session', ['email' => $this->person->email, 'password' => 'password'])->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_or_unrelated_session_is_rejected(): void
    {
        $this->withToken($this->login());
        $this->travel(8)->hours();
        $this->identity('profile')->assertUnauthorized();
        $token = $this->person->createToken('unrelated', ['*'], now()->addHour());
        $this->withToken($token->plainTextToken);
        $this->identity('profile')->assertUnauthorized();
    }

    public function test_logout_preserves_unrelated_tokens(): void
    {
        $other = $this->person->createToken('unrelated', ['*'], now()->addHour());
        $this->withToken($this->login())->deleteJson('https://field.test/api/v1/field-mobile/session')->assertOk();
        $this->identity('profile')->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($other->plainTextToken));
    }

    public function test_otp_error_classification_recovery_and_no_sensitive_logging(): void
    {
        $this->withToken($this->login());
        $otp = $this->identity('otp-send')->assertOk()->json();
        $this->identity('otp-send')->assertStatus(429)->assertJsonPath('reason', 'OTP_WAIT');
        $wrong = $otp['local_code'] === '111111' ? '222222' : '111111';
        $this->identity('otp-verify', ['otp_uuid' => $otp['otp_uuid'], 'code' => $wrong])
            ->assertUnprocessable()->assertJsonPath('reason', 'OTP_INCORRECT');
        $this->identity('otp-verify', ['otp_uuid' => $otp['otp_uuid'], 'code' => $otp['local_code']])->assertOk();
        $this->identity('profile')->assertOk()->assertJsonPath('verified_otp_uuid', $otp['otp_uuid'])->assertJsonPath('phoneVerified', false);
        $this->identity('otp-verify', ['otp_uuid' => $otp['otp_uuid'], 'code' => $otp['local_code']])
            ->assertUnprocessable()->assertJsonPath('reason', 'OTP_USED');
        $audit = json_encode(DB::table('field_device_audit_events')->get());
        $this->assertStringNotContainsString($otp['local_code'], $audit);
        $this->assertStringNotContainsString($this->fixturePhone, $audit);
        foreach (['info', 'debug', 'error', 'warning'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_expired_otp_and_no_debug_trace_or_request_echo(): void
    {
        $this->withToken($this->login());
        $otp = $this->identity('otp-send')->assertOk()->json();
        $this->travel(5)->minutes();
        config(['app.debug' => true]);
        $response = $this->identity('otp-verify', ['otp_uuid' => $otp['otp_uuid'], 'code' => $otp['local_code']])
            ->assertUnprocessable()->assertJsonPath('reason', 'OTP_EXPIRED');
        foreach (['trace', 'exception', 'password', 'local_code', $this->fixturePhone] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_profile_rechecks_link_and_rejects_client_identity(): void
    {
        $this->withToken($this->login());
        $this->identity('profile', ['employee_id' => 99, 'phone' => $this->fixturePhone])->assertUnprocessable();
        $this->person->forceFill(['employee_id' => null])->save();
        $this->identity('profile')->assertUnauthorized();
        $this->assertDatabaseCount('employee_devices', 0);
    }

    public function test_existing_identity_endpoint_cannot_bypass_https_in_local(): void
    {
        $token = $this->person->createToken('isolated-field-test', ['field-device:enroll'], now()->addHour());
        $this->app->instance('env', 'local');
        $this->withToken($token->plainTextToken)->postJson('/api/v1/field-identity/otp-send')->assertForbidden();
        $this->assertDatabaseCount('field_device_otps', 0);
    }

    public function test_demo_human_api_registration_retry_proof_replay_and_fresh_restart_signature(): void
    {
        $plain = $this->login();
        $this->withToken($plain);
        $otp = $this->identity('otp-send')->assertOk()->json();
        $this->identity('otp-verify', ['otp_uuid' => $otp['otp_uuid'], 'code' => $otp['local_code']])->assertOk();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $input = [
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'otp_uuid' => $otp['otp_uuid'], 'public_key' => openssl_pkey_get_details($key)['key'],
            'platform' => 'android', 'platform_version' => 'test', 'app_version' => 'test',
            'hardware_model' => 'Synthetic', 'replaces_uuid' => null,
        ];
        $this->identity('register', $input)->assertOk()->assertJsonPath('status', 'PENDING');
        $this->identity('register', $input)->assertOk()->assertJsonPath('status', 'PENDING');
        $challenge = $this->identity('challenge', ['device_uuid' => $input['device_uuid']])->assertOk()->json();
        openssl_sign($challenge['message'], $signature, $key, OPENSSL_ALGO_SHA256);
        $proof = ['challenge_uuid' => $challenge['challenge_uuid'], 'signature' => base64_encode($signature)];
        $this->identity('prove', $proof)->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('phoneVerified', false);
        $this->identity('prove', $proof)->assertForbidden();
        auth()->forgetGuards();
        $this->withToken($plain);
        $challenge = $this->identity('challenge', ['device_uuid' => $input['device_uuid'], 'purpose' => 'ACTOR'])->assertOk()->json();
        openssl_sign($challenge['message'], $signature, $key, OPENSSL_ALGO_SHA256);
        $this->identity('prove', ['challenge_uuid' => $challenge['challenge_uuid'],
            'signature' => base64_encode($signature), 'purpose' => 'ACTOR'])
            ->assertOk()->assertJsonPath('context.deviceVerified', true)->assertJsonPath('context.phoneVerified', false);
        $bindingBefore = (array) DB::table('employee_devices')->first();
        $otpBefore = DB::table('field_device_otps')->get()->toJson();
        $newOrigin = 'https://192.168.1.80:8443/api/v1/field-mobile/';
        auth()->forgetGuards();
        $newSession = $this->postJson($newOrigin.'session', [
            'email' => $this->person->email, 'password' => 'password',
        ])->assertOk()->json('token');
        $this->assertNotSame($plain, $newSession);
        $this->withToken($newSession);
        $this->postJson($newOrigin.'profile')->assertOk()
            ->assertJsonPath('employee.number', '990001005')
            ->assertJsonPath('devices.0.device_uuid', $input['device_uuid'])
            ->assertJsonPath('devices.0.status', 'ACTIVE');
        $challenge = $this->postJson($newOrigin.'challenge', [
            'device_uuid' => $input['device_uuid'], 'purpose' => 'ACTOR',
        ])->assertOk()->json();
        openssl_sign($challenge['message'], $signature, $key, OPENSSL_ALGO_SHA256);
        $proof = ['challenge_uuid' => $challenge['challenge_uuid'], 'signature' => base64_encode($signature), 'purpose' => 'ACTOR'];
        $this->postJson($newOrigin.'prove', $proof)->assertOk()->assertJsonPath('context.deviceVerified', true);
        $this->postJson($newOrigin.'prove', $proof)->assertForbidden();
        $bindingAfter = (array) DB::table('employee_devices')->first();
        foreach (['id', 'uuid', 'public_key', 'key_fingerprint', 'key_version', 'activated_at', 'user_id', 'employee_id', 'status'] as $field) {
            $this->assertSame($bindingBefore[$field], $bindingAfter[$field], $field);
        }
        $this->assertSame($otpBefore, DB::table('field_device_otps')->get()->toJson());
        $this->assertDatabaseCount('employee_devices', 1);
        $this->assertNull(User::authenticatedEmployee());
        foreach (['attendance_logs', 'vending_attendance_events', 'vending_support_activities'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
