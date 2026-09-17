<?php

namespace App\Http\Middleware;

use App\Support\StatefulDomainPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateStatefulDomains
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! StatefulDomainPolicy::isValid(config('sanctum.stateful'))) {
            return response()->json(['message' => 'Invalid authentication configuration.'], 503)
                ->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
