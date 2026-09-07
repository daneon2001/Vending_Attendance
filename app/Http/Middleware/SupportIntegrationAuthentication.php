<?php

namespace App\Http\Middleware;

use App\Models\SupportIntegration;
use App\Services\Support\SupportActor;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class SupportIntegrationAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        $plain = $request->bearerToken();
        abort_unless(is_string($plain) && $plain !== '' && ! $request->headers->has('Cookie'), 401, 'Autenticación de servicio requerida.');
        $token = PersonalAccessToken::findToken($plain);
        $principal = $token?->tokenable;
        abort_unless($principal instanceof SupportIntegration && $principal->active
            && $token->expires_at !== null && $token->expires_at->isFuture(), 401, 'Credencial de servicio no válida.');
        $abilities = $token->abilities ?? [];
        abort_if($abilities === [] || array_diff($abilities, config('support.integration_scopes')) !== [], 401, 'Credencial de servicio no válida.');
        $request->attributes->set('support_actor', SupportActor::integration($principal, $abilities));

        // Never set Auth::user(): a service is not a human or a Device.
        return $next($request);
    }
}
