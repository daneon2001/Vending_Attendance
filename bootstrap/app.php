<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Middleware propios
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\CheckTokenExpiration;

// Middleware de Laravel
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

// (Solo lo usarías si tuvieras SPA con cookies, para tokens Bearer no es necesario)
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use App\Console\Commands\EnsureAdminPermissions;
use App\Console\Commands\MakeAdminSuperCommand;
use App\Console\Commands\FortiaMockAddEmployee;
use App\Console\Commands\FortiaMockSyncEmployees;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php', // por si no estaba
    )
    ->withCommands([
        FortiaMockAddEmployee::class,
        FortiaMockSyncEmployees::class,
        EnsureAdminPermissions::class,
        MakeAdminSuperCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Grupo WEB (Inertia, etc.)
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Grupo API
        $middleware->api(prepend: [
            // Si fueras a usar Sanctum con COOKIES (SPA) lo activas:
            // EnsureFrontendRequestsAreStateful::class,

            // En APIs puras con Bearer no es obligatorio,
            // pero SubstituteBindings viene bien para route model binding.
            SubstituteBindings::class,
        ]);

        // Aliases de middleware (los que usarás en las rutas)
        $middleware->alias([
            // auth por defecto, permite usar 'auth' y 'auth:sanctum' en las rutas
            'auth'             => Authenticate::class,
            'guest'            => RedirectIfAuthenticated::class,
            'verified'         => EnsureEmailIsVerified::class,

            // Nuestro middleware de expiración de token
            'token.expiration' => CheckTokenExpiration::class,
            'perm'             => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
