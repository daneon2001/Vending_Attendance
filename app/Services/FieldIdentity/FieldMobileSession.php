<?php

namespace App\Services\FieldIdentity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

/** Domain-separated digest: these tokens cannot authenticate any Sanctum API. */
final class FieldMobileSession
{
    public const NAME = 'field-mobile-human-v1';

    public static function digest(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', 'FIELD_MOBILE_SESSION_V1:'.$token);
    }

    public function login(string $email, #[\SensitiveParameter] string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $rateKey = 'field-mobile-login:'.hash('sha256', $email);
        abort_if(RateLimiter::tooManyAttempts($rateKey, 5), 429, 'Espera unos minutos antes de volver a intentar.');
        RateLimiter::hit($rateKey, 300);
        $user = User::where('email', $email)->first();
        // Equal-cost password work for unknown accounts; no credential is logged.
        $valid = Hash::check($password, $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        abort_unless($valid && $user && app(EnrollmentIdentity::class)->resolve($user), 401, 'No fue posible iniciar sesión con estos datos.');

        return DB::transaction(function () use ($user, $rateKey): array {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(EnrollmentIdentity::class)->resolve($user), 401, 'No fue posible iniciar sesión con estos datos.');
            // Bound issuance, preserve other sessions and all terminal credentials.
            $user->tokens()->where('name', self::NAME)->where('expires_at', '<=', now())->delete();
            abort_if($user->tokens()->where('name', self::NAME)->count() >= 5, 429, 'Cierra una sesión de Mi dispositivo antes de continuar.');
            $plain = 'fm_'.bin2hex(random_bytes(32));
            $expires = now()->addHours(8);
            $user->tokens()->create([
                'name' => self::NAME, 'token' => self::digest($plain),
                'abilities' => ['field-device:enroll'], 'expires_at' => $expires,
            ]);
            RateLimiter::clear($rateKey);

            return ['token' => $plain, 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function authenticate(#[\SensitiveParameter] ?string $plain): ?User
    {
        if (! is_string($plain) || preg_match('/^fm_[a-f0-9]{64}$/D', $plain) !== 1) {
            return null;
        }
        $token = PersonalAccessToken::where('token', self::digest($plain))->where('name', self::NAME)->first();
        $user = $token?->tokenable;
        if (! $token || ! $token->expires_at?->isFuture()
            || $token->abilities !== ['field-device:enroll']
            || ! $user instanceof User || ! app(EnrollmentIdentity::class)->resolve($user)) {
            return null;
        }

        return $user->withAccessToken($token);
    }
}
