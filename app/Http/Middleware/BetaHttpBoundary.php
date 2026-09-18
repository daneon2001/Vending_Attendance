<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BetaHttpBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('beta')) {
            return $next($request);
        }
        $proxies = config('internal_beta.trusted_proxies', []);
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! \App\Support\InternalBeta::enabled() || ! is_array($proxies)
            || in_array('*', $proxies, true) || ! is_string($host)
            || parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            return response()->json(['status' => 'not_ready'], 503);
        }
        Request::setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        if (! hash_equals(strtolower($host), strtolower($request->getHost()))) {
            return response()->json(['message' => 'Invalid host.'], 400);
        }
        if (! $request->secure()) {
            return response()->json(['message' => 'HTTPS required.'], 403);
        }
        return $next($request);
    }
}
