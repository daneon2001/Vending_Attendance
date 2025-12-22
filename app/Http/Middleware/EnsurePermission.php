<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $request->user();

        if (! $user || (! $user->hasPermission($module, $action) && ! $user->hasPermission('settings', 'manage'))) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'No tienes permisos para realizar esta acción.',
                ], 403);
            }

            abort(403, 'No tienes permisos para realizar esta acción.');
        }

        return $next($request);
    }
}
