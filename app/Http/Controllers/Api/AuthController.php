<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function authenticate(Request $request)
    {
        // Ajusta los nombres de campos a lo que venga en la guía.
        // Ejemplo: "user" y "password"
        $credentials = $request->validate([
            'user'     => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt(['email' => $credentials['user'], 'password' => $credentials['password']])) {
            AuditLogger::log(
                event: 'auth.login.failed',
                auditable: null,
                description: 'Authentication failed',
                metadata: [
                    'action' => 'login',
                    'entity' => 'auth',
                    'reason' => 'invalid_credentials',
                    'new_values' => ['user' => $credentials['user']],
                ]
            );

            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = $request->user();

        // Crear token Sanctum
        $tokenResult = $user->createToken('opensync');
        $plainTextToken = $tokenResult->plainTextToken;

        // Establecer expiración (30 minutos)
        $accessToken = $tokenResult->accessToken;
        $accessToken->expires_at = now()->addMinutes(30);
        $accessToken->save();

        AuditLogger::log(
            event: 'auth.login.success',
            auditable: $user,
            description: 'Authentication success',
            metadata: [
                'action' => 'login',
                'entity' => 'users',
                'entity_id' => (string) $user->id,
                'new_values' => [
                    'token_id' => $accessToken->id,
                    'expires_at' => $accessToken->expires_at?->toIso8601String(),
                ],
            ]
        );

        // Estructura de respuesta: emulamos algo tipo Fortia
        return response()->json([
            'token'       => $plainTextToken,
            'token_type'  => 'Bearer',
            'expires_in'  => 1800, // segundos (30 min)
            'expires_at'  => $accessToken->expires_at->toIso8601String(),
            // Puedes agregar campos que veas en la guía (user info, etc.)
        ]);
    }
}
