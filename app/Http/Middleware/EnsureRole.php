<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || $roles === []) {
            return $this->forbidden($request);
        }

        $allowed = collect($roles)
            ->flatMap(fn (string $role) => explode('|', $role))
            ->map(fn (string $role) => $this->normalizeRole($role))
            ->filter()
            ->values()
            ->all();

        if ($allowed === []) {
            return $this->forbidden($request);
        }

        $userRoles = $user->roles()
            ->select('name')
            ->pluck('name')
            ->map(fn (string $name) => $this->normalizeRole($name))
            ->filter()
            ->values()
            ->all();

        $hasRole = count(array_intersect($allowed, $userRoles)) > 0;

        if (! $hasRole) {
            return $this->forbidden($request);
        }

        return $next($request);
    }

    private function normalizeRole(string $role): string
    {
        $normalized = trim(strtolower($role));
        $normalized = str_replace([' ', '_'], '', $normalized);

        return match ($normalized) {
            'administrator' => 'administrador',
            'admin' => 'administrador',
            default => $normalized,
        };
    }

    private function forbidden(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'No tienes permisos para realizar esta acción.',
            ], 403);
        }

        abort(403, 'No tienes permisos para realizar esta acción.');
    }
}
