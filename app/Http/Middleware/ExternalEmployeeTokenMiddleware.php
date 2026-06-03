<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ExternalEmployeeTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = trim((string) config('services.employee_lookup_api.token', ''));

        if ($configuredToken === '') {
            Log::warning('external.employee_lookup.token_not_configured', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->jsonResponse(
                success: false,
                message: 'Servicio no disponible.',
                status: 503,
            );
        }

        $providedToken = $this->resolveProvidedToken($request);

        if ($providedToken === null || $providedToken === '') {
            Log::warning('external.employee_lookup.token_missing', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->jsonResponse(
                success: false,
                message: 'Token requerido.',
                status: 401,
            );
        }

        if (! hash_equals($configuredToken, $providedToken)) {
            Log::warning('external.employee_lookup.token_invalid', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->jsonResponse(
                success: false,
                message: 'Token inválido.',
                status: 403,
            );
        }

        return $next($request);
    }

    private function resolveProvidedToken(Request $request): ?string
    {
        $bearerToken = $request->bearerToken();

        if (is_string($bearerToken) && trim($bearerToken) !== '') {
            return trim($bearerToken);
        }

        $headerToken = $request->header('X-Employee-Api-Token');

        return is_string($headerToken) && trim($headerToken) !== ''
            ? trim($headerToken)
            : null;
    }

    private function jsonResponse(bool $success, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
        ], $status);
    }
}
