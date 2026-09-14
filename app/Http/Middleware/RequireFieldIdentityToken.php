<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class RequireFieldIdentityToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->expires_at
            && $token->expires_at->isFuture() && $token->can('field-device:enroll'), 403,
            'Se requiere una sesión móvil autorizada y vigente.');

        return $next($request);
    }
}
