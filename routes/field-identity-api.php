<?php

use App\Http\Controllers\FieldIdentity\DeviceIdentityController;
use Illuminate\Support\Facades\Route;

// Separate from /v1/device and its HMAC/CSRF exception. No terminal access.
Route::post('v1/field-identity/{identity_action}', DeviceIdentityController::class)
    ->whereIn('identity_action', ['otp-send', 'otp-verify', 'register', 'challenge', 'prove', 'revoke'])
    ->middleware(['auth:sanctum', \App\Http\Middleware\RequireFieldIdentityToken::class, 'token.expiration', 'throttle:20,1'])
    ->name('field-identity.execute');
