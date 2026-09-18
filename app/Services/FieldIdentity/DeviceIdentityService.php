<?php

namespace App\Services\FieldIdentity;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class DeviceIdentityService
{
    public function __construct(
        private readonly RegisteredPhoneSource $phones,
        private readonly DeviceSignature $signatures,
        private readonly OtpProvider $otpProvider = new LocalOtpProvider,
    ) {}

    /** Read-only own profile; never infer activation from a local cached state. */
    public function profile(): array
    {
        abort_unless(\App\Support\InternalBeta::simulationAllowed(), 503);
        $user = auth()->user();
        $employee = $user instanceof User ? app(EnrollmentIdentity::class)->resolve($user) : null;
        abort_unless($employee, 403, 'Identidad no disponible.');
        $phone = $this->phone($employee);
        $devices = EmployeeDevice::where('user_id', $user->id)->where('employee_id', $employee->id)
            ->whereIn('status', ['ACTIVE', 'PENDING', 'REVOKED', 'REPLACED'])
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(10)->get();
        $otp = DB::table('field_device_otps')->where('user_id', $user->id)
            ->where('employee_id', $employee->id)->whereNotNull('verified_at')
            ->whereNull('consumed_at')->where('expires_at', '>', CarbonImmutable::now('UTC'))->first();
        $verifiedOtp = $otp && $phone !== null && $phone === $this->otpContext($otp)['phone']
            ? $otp->uuid : null;

        return [
            'employee' => ['name' => $employee->full_name, 'number' => $employee->employee_number],
            'phone' => $phone === null ? null : MexicanPhone::masked($phone),
            'phoneVerified' => false, 'phone_verification_method' => 'LOCAL_SIMULATED',
            'verified_otp_uuid' => $verifiedOtp,
            'devices' => $devices->map(fn (EmployeeDevice $device) => $this->present($device))->all(),
        ];
    }

    /** Local simulated delivery only; trusted phone never comes from input. */
    public function sendOtp(?string $deviceUuid = null): array
    {
        abort_unless(\App\Support\InternalBeta::simulationAllowed(), 503, 'Verificación no disponible.');

        return $this->run(function (User $user, Employee $employee) use ($deviceUuid): array {
            if (($deviceUuid !== null && ! Str::isUuid($deviceUuid))
                || ($employee->source === \App\Enums\Employees\EmployeeSource::MANUAL && $deviceUuid === null)) {
                return ['error' => 422];
            }
            $phone = $this->phone($employee);
            if ($phone === null) {
                return ['error' => 409];
            }
            $now = CarbonImmutable::now('UTC');
            $old = DB::table('field_device_otps')->where('user_id', $user->id)->first();
            if ($old && (($old->locked_until && $now->lt($old->locked_until))
                || $now->lt(CarbonImmutable::parse($old->sent_at, 'UTC')->addSeconds(60))
                || ($now->lt(CarbonImmutable::parse($old->send_window_at, 'UTC')->addHour()) && $old->sends >= 3))) {
                return ['error' => 429, 'reason' => 'OTP_WAIT'];
            }
            $windowOpen = $old && $now->lt(CarbonImmutable::parse($old->send_window_at, 'UTC')->addHour());
            $attempts = $old && ! ($old->locked_until && $now->gte($old->locked_until)) ? $old->attempts : 0;
            $code = (string) random_int(100000, 999999);
            $uuid = (string) Str::uuid();
            DB::table('field_device_otps')->updateOrInsert(['user_id' => $user->id], [
                'employee_id' => $employee->id, 'uuid' => $uuid, 'phone' => Crypt::encryptString($deviceUuid === null ? $phone : json_encode([
                    'version' => 1, 'phone' => $phone, 'device_uuid' => strtolower($deviceUuid),
                    'attempt_uuid' => $uuid,
                ], JSON_THROW_ON_ERROR)),
                'code_hash' => Hash::make($code), 'attempts' => $attempts,
                'sends' => $windowOpen ? $old->sends + 1 : 1,
                'send_window_at' => $windowOpen ? $old->send_window_at : $now,
                'sent_at' => $now, 'expires_at' => $now->addMinutes(5),
                'locked_until' => null, 'verified_at' => null, 'consumed_at' => null,
            ]);
            $this->audit($user, $employee, 'OTP_SENT');

            return ['otp_uuid' => $uuid, 'phone' => MexicanPhone::masked($phone),
                'local_code' => $this->otpProvider->deliver($phone, $code), 'simulation' => true];
        });
    }

    public function verifyOtp(string $uuid, #[\SensitiveParameter] string $code, ?string $deviceUuid = null): array
    {
        abort_unless(\App\Support\InternalBeta::simulationAllowed(), 503, 'Verificación no disponible.');

        return $this->run(function (User $user, Employee $employee) use ($uuid, $code, $deviceUuid): array {
            $otp = DB::table('field_device_otps')->where('user_id', $user->id)->first();
            $now = CarbonImmutable::now('UTC');
            if ($otp && $otp->locked_until && $now->lt($otp->locked_until)) {
                return ['error' => 429, 'reason' => 'OTP_LOCKED'];
            }
            $valid = $otp && hash_equals($otp->uuid, $uuid) && (int) $otp->employee_id === $employee->id
                && ! $otp->consumed_at && ! $otp->verified_at && $now->lt($otp->expires_at)
                && $this->phone($employee) === $this->otpContext($otp)['phone']
                && $this->otpDeviceMatches($otp, $deviceUuid)
                && preg_match('/^[0-9]{6}$/D', $code) && Hash::check($code, $otp->code_hash);
            if (! $valid) {
                if ($otp) {
                    $attempts = $otp->attempts + 1;
                    DB::table('field_device_otps')->where('user_id', $user->id)->update([
                        'attempts' => $attempts,
                        'locked_until' => $attempts >= 5 ? $now->addMinutes(15) : null,
                    ]);
                }

                $reason = 'OTP_INCORRECT';
                if ($otp && hash_equals($otp->uuid, $uuid)) {
                    $reason = $otp->consumed_at || $otp->verified_at ? 'OTP_USED'
                        : ($now->gte($otp->expires_at) ? 'OTP_EXPIRED' : $reason);
                    if ($attempts >= 5) {
                        $reason = 'OTP_LOCKED';
                    }
                }

                return ['error' => 422, 'reason' => $reason];
            }
            DB::table('field_device_otps')->where('user_id', $user->id)->update([
                'verified_at' => $now, 'attempts' => 0, 'code_hash' => Hash::make(Str::random(64)),
            ]);
            $this->audit($user, $employee, 'OTP_VERIFIED');

            return ['verified' => true];
        });
    }

    public function register(array $input): array
    {
        $data = Validator::make($input, [
            'operation_uuid' => 'required|uuid', 'device_uuid' => 'required|uuid',
            'otp_uuid' => 'required|uuid', 'public_key' => 'required|string|max:1024',
            'platform' => 'required|in:android,ios', 'platform_version' => 'required|string|max:64',
            'app_version' => 'required|string|max:64', 'hardware_model' => 'required|string|max:120',
            'replaces_uuid' => 'nullable|uuid',
        ])->validate();
        foreach (['operation_uuid', 'device_uuid', 'otp_uuid', 'replaces_uuid'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = strtolower($data[$field]);
            }
        }
        $data['public_key'] = $this->signatures->canonicalPublicKey($data['public_key']);
        $data['replaces_uuid'] ??= null;
        // Stable field order; never hash arbitrary client fields or OTP plaintext.
        ksort($data);
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $this->run(function (User $user, Employee $employee) use ($data, $requestHash): array {
            if (! $this->rate($user, 'REGISTER_REQUEST', 10)) {
                return ['error' => 429];
            }
            $this->audit($user, $employee, 'REGISTER_REQUEST');
            $existing = EmployeeDevice::where('operation_uuid', $data['operation_uuid'])->first();
            if ($existing) {
                if ($existing->user_id !== $user->id || $existing->employee_id !== $employee->id
                    || ! hash_equals($existing->request_hash, $requestHash)
                    || ! in_array($existing->status, ['PENDING', 'ACTIVE'], true)) {
                    return ['error' => 409];
                }

                return $this->present($existing);
            }
            if (EmployeeDevice::where('uuid', $data['device_uuid'])
                ->orWhere('key_fingerprint', hash('sha256', $data['public_key']))->exists()) {
                return ['error' => 409];
            }
            $now = CarbonImmutable::now('UTC');
            $otp = DB::table('field_device_otps')->where('user_id', $user->id)->first();
            if (! $otp || ! hash_equals($otp->uuid, $data['otp_uuid']) || $otp->employee_id !== $employee->id
                || ! $otp->verified_at || $otp->consumed_at || ! $now->lt($otp->expires_at)
                || ($otp->locked_until && $now->lt($otp->locked_until))
                || $this->phone($employee) !== $this->otpContext($otp)['phone']
                || ! $this->otpDeviceMatches($otp, $data['device_uuid'])) {
                return ['error' => 422];
            }
            $active = EmployeeDevice::where('active_employee_id', $employee->id)->first();
            if (($active?->uuid) !== $data['replaces_uuid']) {
                return ['error' => 409];
            }
            $device = new EmployeeDevice;
            $device->forceFill([
                'uuid' => $data['device_uuid'], 'operation_uuid' => $data['operation_uuid'],
                'user_id' => $user->id, 'employee_id' => $employee->id, 'active_employee_id' => null,
                'public_key' => $data['public_key'], 'key_fingerprint' => hash('sha256', $data['public_key']),
                'platform' => $data['platform'], 'platform_version' => $data['platform_version'],
                'app_version' => $data['app_version'], 'hardware_model' => $data['hardware_model'],
                'key_version' => 1, 'status' => 'PENDING', 'verified_phone' => $this->otpContext($otp)['phone'],
                'phone_verified_at' => $otp->verified_at, 'replaces_id' => $active?->id,
                'request_hash' => $requestHash,
            ])->save();
            DB::table('field_device_otps')->where('user_id', $user->id)->update(['consumed_at' => $now]);
            $this->audit($user, $employee, 'DEVICE_PENDING', $device->uuid);

            return $this->present($device);
        });
    }

    public function challenge(string $uuid, string $purpose = 'ENROLLMENT', ?string $operationHash = null): array
    {
        abort_unless(in_array($purpose, ['ENROLLMENT', 'ACTOR'], true), 422, 'Solicitud no válida.');

        return $this->run(function (User $user, Employee $employee) use ($uuid, $purpose, $operationHash): array {
            if (! $this->rate($user, 'CHALLENGE_ISSUED', 10)) {
                return ['error' => 429];
            }
            $device = $this->owned($user, $employee, $uuid);
            if (! $device || $device->status !== ($purpose === 'ENROLLMENT' ? 'PENDING' : 'ACTIVE')
                || $this->phone($employee) !== $device->verified_phone) {
                return ['error' => 403];
            }
            $now = CarbonImmutable::now('UTC');
            $challengeUuid = (string) Str::uuid();
            $message = "FIELD_MOBILE_V1\n".json_encode([
                'purpose' => $purpose, 'challenge_uuid' => $challengeUuid, 'device_uuid' => $device->uuid,
                'key_fingerprint' => $device->key_fingerprint, 'user_id' => $user->id,
                'employee_id' => $employee->id, 'nonce' => bin2hex(random_bytes(32)),
                'issued_at' => $now->toIso8601String(), 'expires_at' => $now->addMinutes(2)->toIso8601String(),
            ] + ($operationHash === null ? [] : ['field_support_operation_hash' => $operationHash]), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            DB::table('field_device_challenges')->insert([
                'uuid' => $challengeUuid, 'employee_device_id' => $device->id, 'purpose' => $purpose,
                'message' => $message, 'created_at' => $now, 'expires_at' => $now->addMinutes(2),
            ]);
            $this->audit($user, $employee, 'CHALLENGE_ISSUED', $device->uuid);

            return ['challenge_uuid' => $challengeUuid, 'message' => $message];
        });
    }

    public function prove(string $challengeUuid, string $signature, string $purpose = 'ENROLLMENT'): array
    {
        return $this->run(function (User $user, Employee $employee) use ($challengeUuid, $signature, $purpose): array {
            if (! $this->rate($user, 'PROOF_ATTEMPT', 10)) {
                return ['error' => 429];
            }
            $this->audit($user, $employee, 'PROOF_ATTEMPT');
            $challenge = DB::table('field_device_challenges')->where('uuid', strtolower($challengeUuid))->lockForUpdate()->first();
            $device = $challenge ? EmployeeDevice::whereKey($challenge->employee_device_id)->lockForUpdate()->first() : null;
            $now = CarbonImmutable::now('UTC');
            if (! $challenge || ! $device || $device->user_id !== $user->id || $device->employee_id !== $employee->id
                || $challenge->purpose !== $purpose || $challenge->consumed_at || ! $now->lt($challenge->expires_at)
                || $device->status !== ($purpose === 'ENROLLMENT' ? 'PENDING' : 'ACTIVE')
                || $this->phone($employee) !== $device->verified_phone) {
                return ['error' => 403];
            }
            // Consume even an invalid owned proof; failure must commit this change.
            DB::table('field_device_challenges')->where('uuid', $challenge->uuid)->update(['consumed_at' => $now]);
            if (! $this->signatures->verify($device->public_key, $challenge->message, $signature)) {
                return ['error' => 403];
            }
            if ($purpose === 'ENROLLMENT') {
                $active = EmployeeDevice::where('active_employee_id', $employee->id)->lockForUpdate()->first();
                if ($active?->id !== $device->replaces_id) {
                    return ['error' => 409];
                }
                if ($active) {
                    $active->forceFill(['active_employee_id' => null, 'status' => 'REPLACED', 'ended_at' => $now])->save();
                    $this->audit($user, $employee, 'DEVICE_REPLACED', $active->uuid);
                }
                $device->forceFill(['active_employee_id' => $employee->id, 'status' => 'ACTIVE',
                    'activated_at' => $now, 'verified_at' => $now, 'last_seen_at' => $now])->save();
                $this->audit($user, $employee, 'DEVICE_ACTIVATED', $device->uuid);

                return $this->present($device);
            }
            $device->forceFill(['last_seen_at' => $now])->save();
            $this->audit($user, $employee, 'ACTOR_PROVED', $device->uuid);

            return ['context' => new ActorContext($user->id, $employee->id, $device->id, $device->id,
                false, true, $now->toIso8601String(), $challenge->uuid, 'LOCAL_SIMULATED')];
        });
    }

    public function revoke(string $uuid): array
    {
        return $this->run(function (User $user, Employee $employee) use ($uuid): array {
            $device = $this->owned($user, $employee, $uuid);
            if (! $device) {
                return ['error' => 403];
            }
            if (in_array($device->status, ['ACTIVE', 'PENDING'], true)) {
                $now = CarbonImmutable::now('UTC');
                $device->forceFill(['status' => 'REVOKED', 'active_employee_id' => null,
                    'revoked_at' => $now, 'ended_at' => $now])->save();
                $this->audit($user, $employee, 'DEVICE_REVOKED', $device->uuid);
            }

            return $this->present($device);
        });
    }

    /** Legacy A receipts remain readable; new OTPs bind an installation UUID and attempt. */
    private function otpContext(object $otp): array
    {
        try {
            $plain = Crypt::decryptString($otp->phone);
            if (preg_match('/^\+52[0-9]{10}$/D', $plain)) {
                return ['phone' => $plain, 'device_uuid' => null];
            }
            $value = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
            if (($value['version'] ?? null) === 1 && ($value['attempt_uuid'] ?? null) === $otp->uuid
                && is_string($value['phone'] ?? null) && preg_match('/^\+52[0-9]{10}$/D', $value['phone'])
                && is_string($value['device_uuid'] ?? null) && Str::isUuid($value['device_uuid'])) {
                return $value;
            }
        } catch (\Throwable) {
            // Do not expose encrypted payloads or plaintext phones in diagnostics.
        }

        return ['phone' => null, 'device_uuid' => false];
    }

    private function otpDeviceMatches(object $otp, ?string $uuid): bool
    {
        $context = $this->otpContext($otp);

        return $context['phone'] !== null && ($context['device_uuid'] === null
            || (is_string($uuid) && $context['device_uuid'] === strtolower($uuid)));
    }

    private function phone(Employee $employee): ?string
    {
        $value = $this->phones->forEmployee($employee);
        try {
            return $value === null ? null : MexicanPhone::normalize($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function owned(User $user, Employee $employee, string $uuid): ?EmployeeDevice
    {
        return EmployeeDevice::where('uuid', strtolower($uuid))->where('user_id', $user->id)
            ->where('employee_id', $employee->id)->lockForUpdate()->first();
    }

    private function present(EmployeeDevice $device): array
    {
        return ['device_uuid' => $device->uuid, 'status' => $device->status,
            'phone' => MexicanPhone::masked($device->verified_phone), 'key_version' => $device->key_version,
            'phone_verification_method' => 'LOCAL_SIMULATED', 'phoneVerified' => false, 'simulation' => true];
    }

    private function rate(User $user, string $event, int $maximum): bool
    {
        return DB::table('field_device_audit_events')->where('user_id', $user->id)->where('event', $event)
            ->where('occurred_at', '>', CarbonImmutable::now('UTC')->subMinute())->count() < $maximum;
    }

    private function audit(User $user, Employee $employee, string $event, ?string $deviceUuid = null): void
    {
        DB::table('field_device_audit_events')->insert([
            'user_id' => $user->id, 'employee_id' => $employee->id, 'event' => $event,
            'device_uuid' => $deviceUuid, 'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function run(callable $action): array
    {
        // V1 is a local simulation; no production phone verification provider exists.
        abort_unless(\App\Support\InternalBeta::simulationAllowed(), 503, 'Verificación no disponible.');
        $principal = auth()->user();
        abort_unless($principal instanceof User && $principal->exists, 403, 'Identidad no disponible.');
        $result = DB::transaction(function () use ($principal, $action): array {
            // Serialize OTP, registration, replacement and revocation per real user.
            $user = User::whereKey($principal->getKey())->lockForUpdate()->first();
            abort_unless($user && $user->estatus && $user->employee_id, 403, 'Identidad no disponible.');
            Employee::whereKey($user->employee_id)->lockForUpdate()->first();
            $employee = app(EnrollmentIdentity::class)->resolve($user);
            abort_unless($employee && $employee->id === $user->employee_id, 403, 'Identidad no disponible.');
            $result = $action($user, $employee);
            if (isset($result['error'])) {
                $this->audit($user, $employee, 'IDENTITY_DENIED');
            }

            return $result;
        }, 3);
        abort_if(isset($result['error']), $result['error'] ?? 403,
            'No fue posible verificar el dispositivo. Intenta nuevamente más tarde.',
            ['X-Field-Identity-Error' => $result['reason'] ?? 'IDENTITY_UNAVAILABLE']);

        return $result;
    }
}
