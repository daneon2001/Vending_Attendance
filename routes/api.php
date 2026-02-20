<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AdminEmployeeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogSyncController;
use App\Http\Controllers\Api\ClockController;
use App\Http\Controllers\Api\DashboardSummaryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeFingerprintAccessController;
use App\Http\Controllers\Api\EmployeeTemplatesController;
use App\Http\Controllers\Api\EnrolmentController;
use App\Http\Controllers\Api\FortiaMock\FortiaMockEmployeeController;
use App\Http\Controllers\Api\FortiaMock\FortiaMockSyncController;
use App\Http\Controllers\Api\OnPrem\OnPremAttendanceController;
use App\Http\Controllers\Api\OnPrem\OnPremHeartbeatController;
use Illuminate\Support\Facades\Route;

Route::middleware('device.token')->match(['GET', 'POST'], '/device/ping', function () {
    return response()->json([
        'status' => 'OK',
        'timestamp' => now()->toIso8601String(),
        'device_auth' => true,
    ]);
});

Route::prefix('onprem')
    ->middleware('device.hmac')
    ->group(function (): void {
        Route::post('/attendances', [OnPremAttendanceController::class, 'store']);
        Route::post('/heartbeat', [OnPremHeartbeatController::class, 'store']);
        Route::get('/ping', [OnPremHeartbeatController::class, 'ping']);
    });

Route::prefix('FortiaPrimeApi.Opensync/api/v2')->group(function () {
    // LOGIN (public)
    Route::post('/login/authenticate', [AuthController::class, 'authenticate']);

    // Endpoints de dispositivo (Bearer DEVICE_STATIC_TOKEN)
    Route::middleware('device.token')->group(function () {
        Route::post('/attendance/from-device', [AttendanceController::class, 'storeFromDevice']);
        Route::post('/time-and-assistance/clock-catalog/{clock}/heartbeat', [ClockController::class, 'heartbeatByClock']);
        Route::get('/employees/catalog', [CatalogSyncController::class, 'employeesCatalog']);
        Route::get('/employees/templates', [EmployeeTemplatesController::class, 'index']);
    });

    // APIs protegidas por Sanctum + verificacion de expiracion
    Route::middleware(['auth:sanctum', 'token.expiration'])->group(function () {
        // clock-catalog
        Route::get('/time-and-assistance/clock-catalog', [ClockController::class, 'index']);
        Route::post('/time-and-assistance/clock-catalog', [ClockController::class, 'store']);
        Route::put('/time-and-assistance/clock-catalog/{clock}', [ClockController::class, 'update']);
        Route::post('/time-and-assistance/clock-catalog/{clock}/assign', [ClockController::class, 'assign']);
        Route::post('/time-and-assistance/clock-catalog/heartbeat', [ClockController::class, 'heartbeat']);

        Route::get('/dashboard/summary', DashboardSummaryController::class)->name('dashboard.summary');

        Route::post('/enrolments/complete', [EnrolmentController::class, 'complete']);
        Route::get('/biometrico/catalog', [CatalogSyncController::class, 'catalog']);
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
    Route::post('{employee}/fingerprints', [EmployeeController::class, 'storeFingerprint']);
    Route::delete('{employee}/fingerprints', [EmployeeController::class, 'deleteFingerprint']);
});

Route::prefix('admin')->group(function (): void {
    Route::get('employees', [AdminEmployeeController::class, 'index'])
        ->middleware(['web', 'auth', 'perm:employees,view']);
});

Route::prefix('admin')
    ->middleware([
        'auth:sanctum',
        'audit.biometric',
        'role:administrador,admin',
        'perm.strict:biometrics,fingerprints.read',
        'throttle:biometrics-fingerprints',
    ])
    ->group(function (): void {
        Route::get('employees/{employee}/fingerprints', [EmployeeFingerprintAccessController::class, 'index']);
    });

Route::prefix('superadmin')
    ->middleware([
        'auth:sanctum',
        'audit.biometric',
        'role:superadmin',
        'perm.strict:biometrics,templates.read',
        'throttle:biometrics-templates',
    ])
    ->group(function (): void {
        Route::get('employees/{employee}/fingerprints/templates', [EmployeeFingerprintAccessController::class, 'templates']);
    });

Route::prefix('attendance')->group(function () {
    // Endpoints legacy consumidos por la app on-prem (Python).
    // Solo disponibles en local mediante middleware dev.only.api.
    Route::post('heartbeat', [ClockController::class, 'heartbeat'])->middleware('dev.only.api');
    Route::post('from-device', [AttendanceController::class, 'storeFromDevice'])->middleware('dev.only.api');
    Route::get('employee/{employee}', [AttendanceController::class, 'listByEmployee']);
    Route::post('send-to-fortia', [AttendanceController::class, 'sendToFortia']);
});

Route::prefix('enrolments')->group(function () {
    // Endpoint legacy solo para local (ver middleware dev.only.api).
    Route::post('complete', [EnrolmentController::class, 'complete'])->middleware('dev.only.api');
});

Route::prefix('biometrico')->group(function () {
    // Endpoint legacy solo para local (ver middleware dev.only.api).
    Route::get('catalog', [CatalogSyncController::class, 'catalog'])->middleware('dev.only.api');
});

Route::prefix('fortia-mock')->group(function () {
    Route::get('employees', [FortiaMockEmployeeController::class, 'index']);
    Route::post('employees', [FortiaMockEmployeeController::class, 'store']);
    Route::patch('employees/{employee}/status', [FortiaMockEmployeeController::class, 'updateStatus']);
    Route::post('sync-employees', [FortiaMockSyncController::class, 'sync']);
});

Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
