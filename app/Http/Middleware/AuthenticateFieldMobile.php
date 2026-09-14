<?php

namespace App\Http\Middleware;

use App\Services\FieldIdentity\FieldMobileSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateFieldMobile
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = app(FieldMobileSession::class)->authenticate($request->bearerToken());
        abort_unless($user, 401, 'Tu sesión de Mi dispositivo terminó. Inicia sesión nuevamente.');
        // setUser is request-local authentication, NOT a web session login.
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
