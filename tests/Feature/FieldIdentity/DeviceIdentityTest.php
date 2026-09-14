<?php

namespace Tests\Feature\FieldIdentity;

use App\Enums\Employees\EmployeeSource;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\User;
use App\Services\FieldIdentity\ActorContext;
use App\Services\FieldIdentity\DeviceIdentityService;
use App\Services\FieldIdentity\LocalOtpProvider;
use App\Services\FieldIdentity\MexicanPhone;
use App\Services\FieldIdentity\RegisteredPhoneSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class DeviceIdentityTest extends VendingDeviceApiTestCase
{
    private DeviceIdentityService $service;

    private User $user;

    private Employee $person;

    private ?string $phone = '+525500000001'; // Isolated synthetic fixture, never real delivery.

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(CarbonImmutable::parse('2026-09-09T18:00:00Z'));
        Http::preventStrayRequests();
        $this->person = $this->employee(['source' => EmployeeSource::FORTIA,
            'employee_number' => 'TEST-IDENTITY', 'source_external_id' => 'TEST-1']);
        $this->user = User::factory()->create(['estatus' => true]);
        $this->user->employee()->associate($this->person);
        $this->user->save();
        $this->actingAs($this->user);
        $source = $this->getMockBuilder(RegisteredPhoneSource::class)->onlyMethods(['forEmployee'])->getMock();
        $source->method('forEmployee')->willReturnCallback(fn () => $this->phone);
        $this->app->instance(RegisteredPhoneSource::class, $source);
        $this->service = app(DeviceIdentityService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function denied(callable $action, int $status = 403): void
    {
        try {
            $action();
            $this->fail('Expected denial.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
            if ($this->phone !== null) {
                $this->assertStringNotContainsString($this->phone, $exception->getMessage());
            }
        }
    }

    private function verified(): array
    {
        $otp = $this->service->sendOtp();
        $this->service->verifyOtp($otp['otp_uuid'], $otp['local_code']);

        return $otp;
    }

    private function input(array $otp, ?string $replaces = null): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $input = ['device_uuid' => (string) Str::uuid(), 'operation_uuid' => (string) Str::uuid(),
            'otp_uuid' => $otp['otp_uuid'], 'public_key' => openssl_pkey_get_details($private)['key'],
            'platform' => 'android', 'platform_version' => '15', 'app_version' => 'test',
            'hardware_model' => 'Synthetic', 'replaces_uuid' => $replaces];

        return [$input, $private];
    }

    private function proof(array $challenge, $private, string $purpose = 'ENROLLMENT'): array
    {
        openssl_sign($challenge['message'], $signature, $private, OPENSSL_ALGO_SHA256);

        return $this->service->prove($challenge['challenge_uuid'], base64_encode($signature), $purpose);
    }

    private function active(): array
    {
        [$input, $key] = $this->input($this->verified());
        $this->assertSame('PENDING', $this->service->register($input)['status']);
        $this->assertSame('ACTIVE', $this->proof($this->service->challenge($input['device_uuid']), $key)['status']);

        return [$input, $key];
    }

    public function test_valid_binding_and_actor_context_do_not_grant_permissions_or_create_attendance(): void
    {
        [$input, $key] = $this->active();
        $context = $this->proof($this->service->challenge($input['device_uuid'], 'ACTOR'), $key, 'ACTOR')['context'];
        $this->assertInstanceOf(ActorContext::class, $context);
        $this->assertSame($this->user->id, $context->userId);
        $this->assertSame($this->person->id, $context->employeeId);
        $this->assertSame($context->deviceId, $context->deviceAssignmentId);
        $this->assertFalse($context->phoneVerified, 'Local OTP does not prove real phone possession.');
        $this->assertSame('LOCAL_SIMULATED', $context->phoneVerificationMethod);
        $this->assertFalse($this->user->hasPermission('support', 'report'));
        foreach (['vending_attendance_events', 'attendance_logs', 'vending_support_activities', 'devices', 'employee_machine_assignments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($this->person->id, User::authenticatedEmployee()->id);
    }

    public function test_active_recovery_preserves_binding_and_rejects_replayed_or_wrong_key_actor_proofs(): void
    {
        [$input, $key] = $this->active();
        $before = EmployeeDevice::first()->getRawOriginal();
        $otpBefore = DB::table('field_device_otps')->first();
        $this->travel(5)->seconds();
        $challenge = $this->service->challenge($input['device_uuid'], 'ACTOR');
        $context = $this->proof($challenge, $key, 'ACTOR')['context'];
        $this->assertTrue($context->deviceVerified);
        $this->assertFalse($context->phoneVerified);
        $this->denied(fn () => $this->proof($challenge, $key, 'ACTOR'));
        [, $wrongKey] = $this->input(['otp_uuid' => $input['otp_uuid']]);
        $wrong = $this->service->challenge($input['device_uuid'], 'ACTOR');
        $this->denied(fn () => $this->proof($wrong, $wrongKey, 'ACTOR'));
        $this->denied(fn () => $this->proof($wrong, $key, 'ACTOR'));
        $again = $this->proof($this->service->challenge($input['device_uuid'], 'ACTOR'), $key, 'ACTOR')['context'];
        $this->assertSame($context->deviceId, $again->deviceId);
        $after = EmployeeDevice::first()->getRawOriginal();
        foreach (['id', 'uuid', 'user_id', 'employee_id', 'public_key', 'key_fingerprint', 'key_version', 'activated_at', 'status'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertEquals($otpBefore, DB::table('field_device_otps')->first());
        $this->assertDatabaseCount('employee_devices', 1);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('vending_attendance_events', 0);
        $this->denied(fn () => $this->service->challenge((string) Str::uuid(), 'ACTOR'));
    }

    public function test_phone_normalization_and_masking(): void
    {
        foreach (['55 0000 0001', '+52 (55) 0000-0001'] as $input) {
            $this->assertSame('+525500000001', MexicanPhone::normalize($input));
            $this->assertSame('******0001', MexicanPhone::masked($input));
        }
        foreach (['+1525500000001', '+5215500000001', 'abc5500000001', '00525500000001', '55000000010'] as $input) {
            try {
                MexicanPhone::normalize($input);
                $this->fail('Ambiguous phone accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_default_phone_source_fails_closed_without_writes(): void
    {
        $this->assertNull((new RegisteredPhoneSource)->forEmployee($this->person));
        $this->phone = null;
        $this->denied(fn () => $this->service->sendOtp(), 409);
        $this->assertDatabaseCount('field_device_otps', 0);
    }

    public function test_otp_not_plaintext_or_phone_exposed_in_database_audit_or_result(): void
    {
        $otp = $this->service->sendOtp();
        $stored = DB::table('field_device_otps')->first();
        $this->assertTrue(Hash::check($otp['local_code'], $stored->code_hash));
        $this->assertNotSame($this->phone, $stored->phone);
        $this->assertSame('******0001', $otp['phone']);
        $this->assertTrue($otp['simulation']);
        $audit = json_encode(DB::table('field_device_audit_events')->get());
        $this->assertStringNotContainsString($otp['local_code'], $audit);
        $this->assertStringNotContainsString($this->phone, $audit);
    }

    public function test_wrong_otp_persists_attempts_and_locks_even_across_resend(): void
    {
        $otp = $this->service->sendOtp();
        foreach (range(1, 3) as $unused) {
            $this->denied(fn () => $this->service->verifyOtp($otp['otp_uuid'], 'wrong!'), 422);
        }
        $this->travel(61)->seconds();
        $otp = $this->service->sendOtp();
        foreach (range(1, 2) as $unused) {
            $this->denied(fn () => $this->service->verifyOtp($otp['otp_uuid'], 'wrong!'), 422);
        }
        $this->assertSame(5, DB::table('field_device_otps')->value('attempts'));
        $this->denied(fn () => $this->service->verifyOtp($otp['otp_uuid'], $otp['local_code']), 429);
        $this->denied(fn () => $this->service->sendOtp(), 429);
    }

    public function test_expired_otp_is_rejected_at_exact_utc_boundary(): void
    {
        $otp = $this->service->sendOtp();
        $this->travel(5)->minutes();
        $this->denied(fn () => $this->service->verifyOtp($otp['otp_uuid'], $otp['local_code']), 422);
    }

    public function test_otp_cannot_be_verified_twice(): void
    {
        $otp = $this->verified();
        $this->denied(fn () => $this->service->verifyOtp($otp['otp_uuid'], $otp['local_code']), 422);
    }

    public function test_resend_cooldown_and_hourly_limit(): void
    {
        $this->service->sendOtp();
        $this->denied(fn () => $this->service->sendOtp(), 429);
        foreach (range(1, 2) as $unused) {
            $this->travel(61)->seconds();
            $this->service->sendOtp();
        }
        $this->travel(61)->seconds();
        $this->denied(fn () => $this->service->sendOtp(), 429);
    }

    public function test_phone_change_after_otp_is_rejected(): void
    {
        $otp = $this->service->sendOtp();
        $this->phone = '+525500000002';
        $this->denied(fn () => $this->service->verifyOtp($otp['otp_uuid'], $otp['local_code']), 422);
    }

    public function test_phone_change_after_verification_blocks_registration(): void
    {
        [$input] = $this->input($this->verified());
        $this->phone = '+525500000002';
        $this->denied(fn () => $this->service->register($input), 422);
    }

    public function test_missing_user_missing_link_inactive_user_inactive_employee_are_rejected(): void
    {
        auth()->logout();
        $this->denied(fn () => $this->service->sendOtp());
        $unknown = new User;
        $unknown->id = 999999;
        $this->actingAs($unknown);
        $this->denied(fn () => $this->service->sendOtp());
        $unlinked = User::factory()->create(['estatus' => true]);
        $this->actingAs($unlinked);
        $this->denied(fn () => $this->service->sendOtp());
        $this->actingAs($this->user);
        $this->user->update(['estatus' => false]);
        $this->denied(fn () => $this->service->sendOtp());
        $this->user->update(['estatus' => true]);
        $this->person->update(['status' => 'B']);
        $this->denied(fn () => $this->service->sendOtp());
    }

    public function test_client_employee_phone_and_status_cannot_override_authenticated_identity(): void
    {
        $token = $this->user->createToken('isolated-field-test', ['field-device:enroll'], now()->addMinutes(10));
        auth()->logout();
        auth()->forgetGuards();
        $this->withToken($token->plainTextToken);
        $this->postJson('/api/v1/field-identity/otp-send', [
            'employee_id' => 999999, 'phone' => '+525500000002', 'status' => 'ACTIVE',
        ])->assertUnprocessable()->assertJsonValidationErrors(['employee_id', 'phone', 'status']);
        $this->assertDatabaseCount('field_device_otps', 0);
    }

    public function test_guest_or_terminal_hmac_cannot_use_human_endpoint(): void
    {
        auth()->logout();
        $this->withHeaders(['X-Device-Id' => (string) Str::uuid(), 'X-Signature' => 'not-a-human-session'])
            ->postJson('/api/v1/field-identity/otp-send')->assertUnauthorized();
    }

    public function test_production_provider_and_domain_fail_closed(): void
    {
        $this->app->instance('env', 'production');
        $this->denied(fn () => (new LocalOtpProvider)->deliver($this->phone, '123456'), 503);
        $this->denied(fn () => $this->service->sendOtp(), 503);
        $this->denied(fn () => $this->service->challenge((string) Str::uuid()), 503);
    }

    public function test_registration_retries_are_idempotent_but_changed_key_conflicts(): void
    {
        [$input] = $this->input($this->verified());
        $first = $this->service->register($input);
        $this->assertSame($first, $this->service->register(array_reverse($input, true)));
        $this->assertDatabaseCount('employee_devices', 1);
        [$other] = $this->input(['otp_uuid' => $input['otp_uuid']]);
        $input['public_key'] = $other['public_key'];
        $this->denied(fn () => $this->service->register($input), 409);
    }

    public function test_duplicate_uuid_or_key_and_reused_otp_are_rejected(): void
    {
        [$input] = $this->input($this->verified());
        $this->service->register($input);
        $duplicate = $input;
        $duplicate['operation_uuid'] = (string) Str::uuid();
        $this->denied(fn () => $this->service->register($duplicate), 409);
        $duplicate['device_uuid'] = (string) Str::uuid();
        $this->denied(fn () => $this->service->register($duplicate), 409);
        [$fresh] = $this->input(['otp_uuid' => $input['otp_uuid']]);
        $this->denied(fn () => $this->service->register($fresh), 422);
    }

    public function test_invalid_signature_consumes_challenge_without_activation(): void
    {
        [$input] = $this->input($this->verified());
        $this->service->register($input);
        $challenge = $this->service->challenge($input['device_uuid']);
        $this->denied(fn () => $this->service->prove($challenge['challenge_uuid'], base64_encode('invalid')));
        $this->assertNotNull(DB::table('field_device_challenges')->value('consumed_at'));
        $this->assertSame('PENDING', EmployeeDevice::first()->status);
    }

    public function test_expired_and_replayed_challenges_are_rejected(): void
    {
        [$input, $key] = $this->input($this->verified());
        $this->service->register($input);
        $expired = $this->service->challenge($input['device_uuid']);
        $this->travel(2)->minutes();
        $this->denied(fn () => $this->proof($expired, $key));
        $challenge = $this->service->challenge($input['device_uuid']);
        $this->proof($challenge, $key);
        $this->denied(fn () => $this->proof($challenge, $key));
    }

    public function test_other_key_cannot_impersonate_device(): void
    {
        [$input] = $this->input($this->verified());
        $this->service->register($input);
        [, $wrongKey] = $this->input(['otp_uuid' => $input['otp_uuid']]);
        $challenge = $this->service->challenge($input['device_uuid']);
        $this->denied(fn () => $this->proof($challenge, $wrongKey));
    }

    public function test_other_user_cannot_prove_or_revoke_device(): void
    {
        [$input, $key] = $this->active();
        $challenge = $this->service->challenge($input['device_uuid'], 'ACTOR');
        $other = $this->employee(['source' => EmployeeSource::FORTIA, 'source_external_id' => 'TEST-2']);
        $user = User::factory()->create(['estatus' => true]);
        $user->employee()->associate($other);
        $user->save();
        $this->actingAs($user);
        $this->denied(fn () => $this->proof($challenge, $key, 'ACTOR'));
        $this->denied(fn () => $this->service->revoke($input['device_uuid']));
        $this->assertSame('ACTIVE', EmployeeDevice::first()->status);
    }

    public function test_replacement_requires_new_otp_key_and_proof_and_keeps_history(): void
    {
        [$old, $oldKey] = $this->active();
        $this->travel(61)->seconds();
        [$new, $newKey] = $this->input($this->verified(), $old['device_uuid']);
        $this->service->register($new);
        $this->assertSame('ACTIVE', EmployeeDevice::where('uuid', $old['device_uuid'])->first()->status);
        $this->proof($this->service->challenge($new['device_uuid']), $newKey);
        $this->assertDatabaseCount('employee_devices', 2);
        $this->assertSame(1, EmployeeDevice::whereNotNull('active_employee_id')->count());
        $this->assertSame('REPLACED', EmployeeDevice::where('uuid', $old['device_uuid'])->first()->status);
        $this->denied(fn () => $this->service->challenge($old['device_uuid'], 'ACTOR'));
    }

    public function test_revocation_invalidates_outstanding_proof_and_preserves_employee(): void
    {
        [$input, $key] = $this->active();
        $challenge = $this->service->challenge($input['device_uuid'], 'ACTOR');
        $this->assertSame('REVOKED', $this->service->revoke($input['device_uuid'])['status']);
        $this->denied(fn () => $this->proof($challenge, $key, 'ACTOR'));
        $this->denied(fn () => $this->service->register($input), 409);
        $this->assertDatabaseHas('employees', ['id' => $this->person->id]);
        $this->assertDatabaseCount('employee_devices', 1);
    }

    public function test_identity_keys_phone_and_request_hash_are_hidden_from_model_serialization(): void
    {
        $this->active();
        $data = EmployeeDevice::first()->toArray();
        foreach (['public_key', 'key_fingerprint', 'verified_phone', 'request_hash'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
    }

    public function test_challenge_and_registration_rates_are_bounded(): void
    {
        [$input, $key] = $this->active();
        foreach (range(1, 9) as $unused) {
            $this->service->challenge($input['device_uuid'], 'ACTOR');
        }
        $this->denied(fn () => $this->service->challenge($input['device_uuid'], 'ACTOR'), 429);
        foreach (range(1, 9) as $unused) {
            $this->service->register($input);
        }
        $this->denied(fn () => $this->service->register($input), 429);
    }

    public function test_bearer_must_have_expiration_and_enrollment_ability(): void
    {
        foreach ([
            [['unrelated:read'], now()->addMinutes(10), 403],
            [['field-device:enroll'], null, 403],
            [['field-device:enroll'], now()->subMinute(), 401],
        ] as [$abilities, $expiry, $status]) {
            $token = $this->user->createToken('isolated-field-test', $abilities, $expiry);
            auth()->forgetGuards();
            $this->withToken($token->plainTextToken)->postJson('/api/v1/field-identity/otp-send')->assertStatus($status);
        }
        $this->assertDatabaseCount('field_device_otps', 0);
    }

    public function test_web_transient_session_is_not_a_field_mobile_token(): void
    {
        $this->postJson('/api/v1/field-identity/otp-send')->assertForbidden();
        $this->assertDatabaseCount('field_device_otps', 0);
    }

    public function test_enrollment_api_uses_an_expiring_human_token_and_no_store_response(): void
    {
        $token = $this->user->createToken('isolated-field-test', ['field-device:enroll'], now()->addMinutes(10));
        auth()->forgetGuards();
        $response = $this->withToken($token->plainTextToken)->postJson('/api/v1/field-identity/otp-send');
        $response->assertOk()->assertJsonPath('simulation', true)->assertJsonPath('phone', '******0001');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_proof_attempt_limit_and_wrong_purpose_are_enforced(): void
    {
        [$input, $key] = $this->active();
        $challenge = $this->service->challenge($input['device_uuid'], 'ACTOR');
        $this->denied(fn () => $this->proof($challenge, $key, 'ENROLLMENT'));
        foreach (range(1, 8) as $unused) {
            $this->denied(fn () => $this->service->prove((string) Str::uuid(), 'invalid'));
        }
        $this->denied(fn () => $this->proof($challenge, $key, 'ACTOR'), 429);
    }

    public function test_second_active_device_requires_explicit_replacement(): void
    {
        $this->active();
        $this->travel(61)->seconds();
        [$input] = $this->input($this->verified());
        $this->denied(fn () => $this->service->register($input), 409);
        $this->assertSame(1, EmployeeDevice::where('status', 'ACTIVE')->count());
    }

    public function test_private_or_wrong_curve_keys_are_not_accepted_as_public_keys(): void
    {
        $verifier = app(\App\Services\FieldIdentity\DeviceSignature::class);
        $this->denied(fn () => $verifier->canonicalPublicKey('-----BEGIN PRIVATE KEY-----redacted-----END PRIVATE KEY-----'), 422);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        $this->denied(fn () => $verifier->canonicalPublicKey(openssl_pkey_get_details($key)['key']), 422);
    }
}
