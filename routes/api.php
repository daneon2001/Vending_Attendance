<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClockController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FortiaMock\FortiaMockEmployeeController;
use App\Http\Controllers\Api\FortiaMock\FortiaMockSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('FortiaPrimeApi.Opensync/api/v2')->group(function () {
    // LOGIN (public)
    Route::post('/login/authenticate', [AuthController::class, 'authenticate']);

    // APIs protegidas por Sanctum + verificaciÓn de expiraciÓn
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

Route::prefix('employees')->group(function () {
    Route::get('/', [EmployeeController::class, 'index']);
    Route::get('{employee}', [EmployeeController::class, 'show']);
    Route::post('sync-fortia', [EmployeeController::class, 'syncFromFortia']);
    Route::post('sync-fortia-mock', [EmployeeController::class, 'syncFortiaMock']);
    Route::patch('{employee}/status', [EmployeeController::class, 'updateStatus']);
    Route::delete('{employee}/fingerprints', [EmployeeController::class, 'deleteFingerprint']);
});

Route::prefix('attendance')->group(function () {
    Route::post('from-device', [AttendanceController::class, 'storeFromDevice']);
    Route::get('employee/{employee}', [AttendanceController::class, 'listByEmployee']);
    Route::post('send-to-fortia', [AttendanceController::class, 'sendToFortia']);
});

Route::prefix('fortia-mock')->group(function () {
    Route::get('employees', [FortiaMockEmployeeController::class, 'index']);
    Route::post('employees', [FortiaMockEmployeeController::class, 'store']);
    Route::patch('employees/{employee}/status', [FortiaMockEmployeeController::class, 'updateStatus']);
    Route::post('sync-employees', [FortiaMockSyncController::class, 'sync']);
});
