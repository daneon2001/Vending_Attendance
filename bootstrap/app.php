<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Middleware propios
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\AuditBiometricAccess;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureStrictPermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CheckTokenExpiration;
use App\Http\Middleware\DevOnlyApi;
use App\Http\Middleware\DeviceTokenMiddleware;
use App\Http\Middleware\ExternalEmployeeTokenMiddleware;
use App\Http\Middleware\VerifyDeviceHmac;

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
use App\Console\Commands\ReconcileDevicesFromClocks;
use App\Console\Commands\AuditCleanupCommand;
use App\Console\Commands\FortiaDiagnoseApis;
use App\Console\Commands\FortiaDiagnoseBiometrics;
use App\Console\Commands\FortiaDiagnoseDeviceToken;
use App\Console\Commands\FortiaDiagnoseOnPrem;
use App\Console\Commands\FortiaAuditCatalogAlignment;
use App\Console\Commands\FortiaAuditClocks;
use App\Console\Commands\FortiaSyncEmployees;
use App\Console\Commands\FortiaImportClocks;
use App\Console\Commands\FortiaSyncOperationalCatalogs;
use App\Console\Commands\SyncPermissionCatalogCommand;
use App\Console\Commands\SybiSyncVendingCommand;
use App\Console\Commands\VendingDemoCleanupCommand;
use App\Console\Commands\VerifyAttendanceIntegrity;
use Illuminate\Http\Request;

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
        FortiaDiagnoseApis::class,
        FortiaDiagnoseBiometrics::class,
        FortiaDiagnoseOnPrem::class,
        FortiaDiagnoseDeviceToken::class,
        FortiaAuditCatalogAlignment::class,
        FortiaAuditClocks::class,
        FortiaImportClocks::class,
        FortiaSyncEmployees::class,
        FortiaSyncOperationalCatalogs::class,
        SyncPermissionCatalogCommand::class,
        SybiSyncVendingCommand::class,
        ReconcileDevicesFromClocks::class,
        VerifyAttendanceIntegrity::class,
        AuditCleanupCommand::class,
        VendingDemoCleanupCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Grupo WEB (Inertia, etc.)
        $middleware->web(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Grupo API
        $middleware->api(prepend: [
            AssignRequestId::class,
            // Permite que auth:sanctum acepte cookies de sesion en el frontend (Inertia/SPA).
            EnsureFrontendRequestsAreStateful::class,

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
            'dev.only.api'     => DevOnlyApi::class,
            'device.token'     => DeviceTokenMiddleware::class,
            'device.hmac'      => VerifyDeviceHmac::class,
            'external.employee.token' => ExternalEmployeeTokenMiddleware::class,
            'perm'             => EnsurePermission::class,
            'perm.strict'      => EnsureStrictPermission::class,
            'role'             => EnsureRole::class,
            'audit.biometric'  => AuditBiometricAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $exception): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
