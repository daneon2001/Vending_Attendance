<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DeviceTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('device.static_token', '');
        $token = $this->extractBearerToken((string) $request->header('Authorization', ''));
        $clockId = $request->route('clock') ?? $request->input('clock_id');
        $ip = (string) $request->ip();

        if ($expected === '' || $token === '' || ! hash_equals($expected, $token)) {
            Log::warning('device.token.invalid', [
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $ip,
                'clock_id' => $clockId,
                'token_present' => $token !== '',
                'expected_configured' => $expected !== '',
            ]);

            return response()->json([
                'message' => 'No autorizado (device token).',
            ], 401);
        }

        Log::info('device.token.accepted', [
            'path' => $request->path(),
            'method' => $request->method(),
            'ip' => $ip,
            'clock_id' => $clockId,
        ]);

        return $next($request);
    }

    private function extractBearerToken(string $authorization): string
    {
        $value = trim($authorization);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $value, $matches) !== 1) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }
}
