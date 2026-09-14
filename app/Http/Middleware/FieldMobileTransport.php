<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FieldMobileTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $this->handleSecure($request, $next);
        } catch (\Illuminate\Validation\ValidationException) {
            return response()->json(['message' => 'Revisa los datos e intenta nuevamente.', 'reason' => 'INVALID_INPUT'], 422)
                ->header('Cache-Control', 'no-store, private');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return response()->json([
                'message' => 'No fue posible completar esta operación.',
                'reason' => $exception->getHeaders()['X-Field-Identity-Error'] ?? 'IDENTITY_UNAVAILABLE',
            ], $exception->getStatusCode())->header('Cache-Control', 'no-store, private');
        } catch (\Throwable) {
            // Local APP_DEBUG must not disclose request data, OTP or session secrets.
            return response()->json(['message' => 'Verificación temporalmente no disponible.'], 503)
                ->header('Cache-Control', 'no-store, private');
        }
    }

    private function handleSecure(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 503, 'La demostración de identidad no está habilitada.');
        abort_unless($request->secure(), 403, 'Mi dispositivo requiere una conexión HTTPS segura.');
        // Native-only credential flow; no cookie, web-session or CSRF bypass.
        abort_if($request->headers->has('Origin') || $request->headers->has('Cookie'), 403, 'Usa Mi dispositivo desde la aplicación Android.');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
