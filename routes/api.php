<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClockController;
use Illuminate\Support\Facades\Route;

Route::prefix('FortiaPrimeApi.Opensync/api/v2')->group(function () {
    // LOGIN (public)
    Route::post('/login/authenticate', [AuthController::class, 'authenticate']);

    // APIs protegidas por Sanctum + verificación de expiración
    Route::middleware(['auth:sanctum', 'token.expiration'])->group(function () {
        // clock-catalog
        Route::get('/time-and-assistance/clock-catalog', [ClockController::class, 'index']);
        Route::post('/time-and-assistance/clock-catalog', [ClockController::class, 'store']);
        Route::put('/time-and-assistance/clock-catalog/{clock}', [ClockController::class, 'update']);
        Route::post('/time-and-assistance/clock-catalog/{clock}/assign', [ClockController::class, 'assign']);

        // employee
        // Route::get('/time-and-assistance/employee', [...]);

        // attendance
        // Route::post('/time-and-assistance/attendance', [...]);

        // shift-profile
        // Route::get('/time-and-assistance/shift-profile/get-by-company', [...]);
    });
});
