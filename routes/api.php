<?php

use App\Http\Controllers\Api\AdminEmployeeController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogSyncController;
use App\Http\Controllers\Api\ClockController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DashboardSummaryController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeFaceProfileController;
use App\Http\Controllers\Api\EmployeeFingerprintAccessController;
use App\Http\Controllers\Api\EmployeeFingerprintDeleteController;
use App\Http\Controllers\Api\EmployeeTemplatesController;
use App\Http\Controllers\Api\EnrolmentController;
use App\Http\Controllers\Api\ExternalEmployeeController;
use App\Http\Controllers\Api\FaceIdTemplateSyncController;
use App\Http\Controllers\Api\FortiaMock\FortiaMockEmployeeController;
use App\Http\Controllers\Api\FortiaMock\FortiaMockSyncController;
use App\Http\Controllers\Api\OnPrem\OnPremAttendanceController;
use App\Http\Controllers\Api\OnPrem\OnPremHeartbeatController;
use App\Http\Controllers\Api\V1\DeviceAttendanceEventBatchController;
use App\Http\Controllers\Api\V1\DeviceAttendanceEventController;
use App\Http\Controllers\Api\V1\DeviceBootstrapController;
use App\Http\Controllers\Api\V1\DeviceConfigurationManifestController;
use App\Http\Controllers\Api\V1\DeviceEmployeeManifestController;
use App\Http\Controllers\Api\V1\DeviceHeartbeatController;
use App\Http\Controllers\Api\V1\DeviceManifestAckController;
use App\Http\Controllers\Api\V1\DeviceManifestStatusController;
use App\Http\Controllers\Api\V1\DeviceProvisioningController;
use App\Http\Controllers\Api\V1\EmployeeVendingMachineController;
use App\Http\Controllers\Api\V1\GeofenceValidationController as V1GeofenceValidationController;
use App\Http\Controllers\Api\V1\VendingMachineController as V1VendingMachineController;
use App\Http\Controllers\Employees\EmployeeImportController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/support-api.php';
require __DIR__.'/field-identity-api.php';
require __DIR__.'/field-mobile-api.php';

Route::prefix('v1/device')->group(function (): void {
    Route::post('provision', DeviceProvisioningController::class)
        ->middleware('throttle:vending-device-provision');

    Route::middleware('device.hmac:vending')->group(function (): void {
        Route::get('bootstrap', DeviceBootstrapController::class)
            ->middleware('throttle:vending-device-bootstrap');
        Route::post('heartbeat', DeviceHeartbeatController::class)
            ->middleware('throttle:vending-device-heartbeat');

        Route::prefix('attendance/events')->group(function (): void {
            Route::post('/', DeviceAttendanceEventController::class)
                ->middleware('throttle:vending-attendance-single');
            Route::post('batch', DeviceAttendanceEventBatchController::class)
                ->middleware('throttle:vending-attendance-batch');
        });

        Route::prefix('manifests')->group(function (): void {
            Route::get('status', DeviceManifestStatusController::class)
                ->middleware('throttle:vending-manifest-status');
            Route::get('configuration', DeviceConfigurationManifestController::class)
                ->middleware('throttle:vending-manifest-download');
            Route::get('employees', DeviceEmployeeManifestController::class)
                ->middleware('throttle:vending-manifest-download');
            Route::post('ack', DeviceManifestAckController::class)
                ->middleware('throttle:vending-manifest-ack');
        });
    });
});

Route::prefix('v1')->middleware(['auth:sanctum', 'perm.strict:vending_machines,view'])->group(function (): void {
    Route::get('vending-machines/{vendingMachine}', [V1VendingMachineController::class, 'show']);
    Route::get('vending-machines/{vendingMachine}/geofence', [V1VendingMachineController::class, 'geofence']);
    Route::get('vending-machines/{vendingMachine}/assignments', [V1VendingMachineController::class, 'assignments']);
    Route::get('employees/{employee}/vending-machines', [EmployeeVendingMachineController::class, 'index']);
    Route::post('geofence/validate', V1GeofenceValidationController::class);
});

Route::middleware('device.token')->match(['GET', 'POST'], '/device/ping', function () {
    return response()->json([
        'status' => 'OK',
        'timestamp' => now()->toIso8601String(),
        'device_auth' => true,
    ]);
});

Route::middleware([
    'auth:sanctum',
    'token.expiration',
    'role:administrador,admin,superadmin',
    'perm.strict:biometrics,face.manage',
])->post('/faceid/templates/sync', [FaceIdTemplateSyncController::class, 'sync']);

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
        Route::get('/time-and-assistance/clock-catalog/resolve-by-serial', [ClockController::class, 'resolveBySerial']);
        Route::get('/time-and-assistance/clock-catalog', [ClockController::class, 'index']);
        Route::post('/time-and-assistance/clock-catalog', [ClockController::class, 'store']);
        Route::put('/time-and-assistance/clock-catalog/{clock}', [ClockController::class, 'update']);
        Route::post('/time-and-assistance/clock-catalog/{clock}/assign', [ClockController::class, 'assign']);
        Route::post('/time-and-assistance/clock-catalog/heartbeat', [ClockController::class, 'heartbeat']);

        Route::get('/dashboard/summary', DashboardSummaryController::class)->name('fortia.dashboard.summary');

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

Route::prefix('employees')
    ->middleware([
        'auth:web,sanctum',
        'role:administrador,admin,superadmin',
    ])
    ->group(function (): void {
        Route::get('/', [EmployeeController::class, 'index'])
            ->middleware('perm.strict:employees,view');
        Route::post('sync-fortia', [EmployeeController::class, 'syncFromFortia'])
            ->middleware('perm.strict:employees,sync');
        Route::patch('{employee}/status', [EmployeeController::class, 'updateStatus'])
            ->middleware('perm.strict:employees,disable');
    });

Route::prefix('admin')->group(function (): void {
    Route::get('employees', [AdminEmployeeController::class, 'index'])
        ->middleware([
            'auth:web,sanctum',
            'role:administrador,admin,superadmin',
            'perm.strict:employees,view',
        ]);
});

Route::middleware(['external.employee.token', 'throttle:employee-lookup'])
    ->group(function (): void {
        Route::get('/external/employees/fortia/{fortiaEmployeeId}', [ExternalEmployeeController::class, 'showByFortia'])
            ->name('api.external.employees.show-by-fortia');
        Route::get('/external/employees/{id}', [ExternalEmployeeController::class, 'show'])
            ->name('api.external.employees.show');
    });

Route::prefix('admin')
    ->middleware([
        'auth:web,sanctum',
        'role:administrador,admin',
        'perm.strict:employees,import',
    ])
    ->group(function (): void {
        Route::post('employees/import/preview', [EmployeeImportController::class, 'preview']);
        Route::post('employees/import/catalogs/missing/create', [EmployeeImportController::class, 'createMissingCatalogs']);
        Route::post('employees/import', [EmployeeImportController::class, 'store']);
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

Route::prefix('admin')
    ->middleware([
        'auth:web,sanctum',
        'audit.biometric',
        'role:administrador,admin,superadmin',
        'perm.strict:biometrics,fingerprints.delete',
        'throttle:biometrics-delete',
    ])
    ->group(function (): void {
        Route::delete('employees/{employee}/fingerprints', [EmployeeFingerprintDeleteController::class, 'destroy']);
    });

Route::prefix('admin')
    ->middleware([
        'auth:web,sanctum',
        'audit.biometric',
        'role:administrador,admin,superadmin',
        'perm.strict:biometrics,face.manage',
        'throttle:biometrics-face',
    ])
    ->group(function (): void {
        Route::patch('employees/{employee}/face-profile', [EmployeeFaceProfileController::class, 'update']);
        Route::delete('employees/{employee}/face-profile', [EmployeeFaceProfileController::class, 'destroy']);
    });

Route::prefix('superadmin')
    ->middleware([
        'auth:web,sanctum',
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

Route::prefix('fortia-mock')->middleware('dev.only.api')->group(function () {
    Route::get('employees', [FortiaMockEmployeeController::class, 'index']);
    Route::post('employees', [FortiaMockEmployeeController::class, 'store']);
    Route::patch('employees/{employee}/status', [FortiaMockEmployeeController::class, 'updateStatus']);
    Route::post('sync-employees', [FortiaMockSyncController::class, 'sync'])
        ->middleware(['auth:web,sanctum', 'perm.strict:employees,sync']);
});

Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
