<?php

use App\Http\Controllers\FieldIdentity\DeviceIdentityController;
use App\Http\Controllers\FieldIdentity\FieldMobileSessionController;
use App\Http\Middleware\AuthenticateFieldMobile;
use App\Http\Middleware\FieldMobileTransport;
use App\Http\Middleware\RequireFieldIdentityToken;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Dedicated native credential transport; no browser session and no CSRF exception.
Route::prefix('v1/field-mobile')->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware([FieldMobileTransport::class, 'throttle:20,1'])->group(function (): void {
        Route::post('session', [FieldMobileSessionController::class, 'store']);
        Route::middleware([AuthenticateFieldMobile::class, RequireFieldIdentityToken::class, 'token.expiration'])
            ->group(function (): void {
                Route::delete('session', [FieldMobileSessionController::class, 'destroy']);
                Route::post('activities/{action}', \App\Http\Controllers\Support\FieldSupportActivityController::class)
                    ->whereIn('action', ['capability', 'challenge', 'execute']);
                Route::post('{identity_action}', DeviceIdentityController::class)
                    ->whereIn('identity_action', ['profile', 'otp-send', 'otp-verify', 'register', 'challenge', 'prove', 'revoke']);
            });
    });
