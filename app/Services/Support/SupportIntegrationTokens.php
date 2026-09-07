<?php

namespace App\Services\Support;

use App\Models\SupportIntegration;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\NewAccessToken;

class SupportIntegrationTokens
{
    // Administrative service seam. No public issuance endpoint and no credentials created by migration.
    public function issue(SupportIntegration $integration, string $name, array $scopes, Carbon $expiresAt): NewAccessToken
    {
        Validator::make(['name' => $name, 'scopes' => $scopes], [
            'name' => 'required|string|max:100', 'scopes' => 'required|array|min:1',
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(config('support.integration_scopes'))],
        ])->validate();
        abort_unless($integration->active && $expiresAt->isFuture(), 422, 'La integración y su vigencia deben estar habilitadas.');
        $secret = Str::random(64);
        $token = $integration->tokens()->create([
            'name' => $name, 'token' => hash('sha256', $secret), 'abilities' => $scopes, 'expires_at' => $expiresAt,
        ]);
        AuditLogger::log('support.integration.token_issued', $integration, 'Credencial de integración emitida', ['token_id' => $token->id, 'scopes' => $scopes]);

        return new NewAccessToken($token, $token->id.'|'.$secret);
    }

    public function revoke(SupportIntegration $integration, int $tokenId): void
    {
        $integration->tokens()->whereKey($tokenId)->delete();
        AuditLogger::log('support.integration.token_revoked', $integration, 'Credencial de integración revocada', ['token_id' => $tokenId]);
    }
}
