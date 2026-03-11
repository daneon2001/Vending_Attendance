<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTokenExpiration
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $token = $user->currentAccessToken();

        if (!$token) {
            return response()->json(['message' => 'Token not found'], 401);
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            // opcional: revocar token
            $token->delete();

            return response()->json(['message' => 'Token expired'], 401);
        }

        return $next($request);
    }
}
