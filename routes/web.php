<?php

use App\Http\Controllers\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Api\AuditCleanupController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\UserController as ApiUserController;
use App\Http\Controllers\AttendanceCardController;
use App\Http\Controllers\ClockCatalogController;
use App\Http\Controllers\ClockController;
use App\Http\Controllers\ClockImportController;
use App\Http\Controllers\ClockLogController;
use App\Http\Controllers\CompanyCatalogController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\Dashboard\CorporateRecruitmentDashboardController;
use App\Http\Controllers\Employees\EmployeeCatalogExportController;
use App\Http\Controllers\Employees\EmployeeCatalogPageController;
use App\Http\Controllers\Employees\VendingEmployeeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Settings\AuditCleanupPageController;
use App\Http\Controllers\Settings\AuditPageController;
use App\Http\Controllers\Settings\RoleAssignmentController;
use App\Http\Controllers\Settings\RoleController as SettingsRoleController;
use App\Http\Controllers\Settings\RolePageController;
use App\Http\Controllers\Settings\SettingsIndexController;
use App\Http\Controllers\Settings\UserPageController;
use App\Http\Controllers\UnitCatalogController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\Vending\DeviceAdministrationController;
use App\Http\Controllers\Vending\EmployeeMachineAssignmentController;
use App\Http\Controllers\Vending\MachineGeofenceController;
use App\Http\Controllers\Vending\MobileReleaseController;
use App\Http\Controllers\Vending\SybiVendingSyncController;
use App\Http\Controllers\Vending\VendingDeviceRegistryController;
use App\Http\Controllers\Vending\VendingFleetDashboardController;
use App\Http\Controllers\Vending\VendingMachineController;
use App\Models\Company;
use App\Models\Location;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    $companies = Company::query()
        ->select('id', 'name', 'code')
        ->orderBy('name')
        ->get();

    $locations = Location::query()
        ->select('id', 'name', 'code', 'company_id')
        ->orderBy('name')
        ->get();

    return Inertia::render('Dashboard', [
        'companies' => $companies,
        'locations' => $locations,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/vending/employees', [VendingEmployeeController::class, 'index'])
        ->middleware('perm.strict:employees,view')->name('vending-employees.index');
    Route::post('/vending/employees/imports', [VendingEmployeeController::class, 'upload'])
        ->middleware(['perm.strict:employees,import', 'throttle:10,1'])->name('vending-employees.imports.store');
    Route::get('/vending/employees/imports/{uuid}', [VendingEmployeeController::class, 'preview'])
        ->middleware('perm.strict:employees,import')->name('vending-employees.imports.show');
    Route::post('/vending/employees/imports/{uuid}/apply', [VendingEmployeeController::class, 'apply'])
        ->middleware(['perm.strict:employees,import', 'throttle:10,1'])->name('vending-employees.imports.apply');
    Route::post('/vending/employees/fortia-sync', [VendingEmployeeController::class, 'sync'])
        ->middleware(['perm.strict:employees,sync', 'throttle:3,1'])->name('vending-employees.sync');
    Route::get('/vending', VendingFleetDashboardController::class)
        ->middleware('perm:vending_machines,view')->name('vending-fleet.dashboard');
    Route::get('/vending/devices', VendingDeviceRegistryController::class)
        ->middleware('perm:vending_machines,view')->name('vending-devices.index');
    Route::get('/vending/releases', [MobileReleaseController::class, 'index'])
        ->middleware('perm:vending_machines,view')->name('vending-releases.index');
    Route::post('/vending/releases', [MobileReleaseController::class, 'store'])
        ->middleware('perm:vending_machines,manage')->name('vending-releases.store');
    Route::put('/vending/releases/policy', [MobileReleaseController::class, 'updatePolicy'])
        ->middleware('perm:vending_machines,manage')->name('vending-releases.policy.update');
    Route::post('/vending/releases/{mobileRelease}/targets', [MobileReleaseController::class, 'addTarget'])
        ->middleware('perm:vending_machines,manage')->name('vending-releases.targets.store');
    Route::patch('/vending/releases/{mobileRelease}/block', [MobileReleaseController::class, 'block'])
        ->middleware('perm:vending_machines,manage')->name('vending-releases.block');
    Route::get('/vending-machines', [VendingMachineController::class, 'index'])
        ->middleware('perm:vending_machines,view')->name('vending-machines.index');
    Route::post('/vending-machines', [VendingMachineController::class, 'store'])
        ->middleware('perm:vending_machines,create')->name('vending-machines.store');
    Route::post('/vending-machines/sybi-sync', SybiVendingSyncController::class)
        ->middleware(['perm:vending_machines,manage', 'throttle:3,1'])
        ->name('vending-machines.sybi-sync');
    Route::get('/vending-machines/{vendingMachine}', [VendingMachineController::class, 'show'])
        ->middleware('perm:vending_machines,view')->name('vending-machines.show');
    Route::put('/vending-machines/{vendingMachine}', [VendingMachineController::class, 'update'])
        ->middleware('perm:vending_machines,update')->name('vending-machines.update');
    Route::post('/vending-machines/{vendingMachine}/assignments', [EmployeeMachineAssignmentController::class, 'store'])
        ->middleware('perm:vending_machines,assign')->name('vending-machines.assignments.store');
    Route::patch('/vending-machines/{vendingMachine}/assignments/{assignment}/revoke', [EmployeeMachineAssignmentController::class, 'revoke'])
        ->middleware('perm:vending_machines,assign')->name('vending-machines.assignments.revoke');
    Route::post('/vending-machines/{vendingMachine}/geofences', [MachineGeofenceController::class, 'store'])
        ->middleware('perm:vending_machines,geofence')->name('vending-machines.geofences.store');
    Route::patch('/vending-machines/{vendingMachine}/geofences/{geofence}/activate', [MachineGeofenceController::class, 'activate'])
        ->middleware('perm:vending_machines,geofence')->name('vending-machines.geofences.activate');
    Route::post('/vending-machines/{vendingMachine}/provisioning-tokens', [DeviceAdministrationController::class, 'createToken'])
        ->middleware('perm:vending_machines,manage')->name('vending-machines.provisioning-tokens.store');
    Route::patch('/vending-machines/{vendingMachine}/provisioning-tokens/{provisioningToken}/revoke', [DeviceAdministrationController::class, 'revokeToken'])
        ->middleware('perm:vending_machines,manage')->name('vending-machines.provisioning-tokens.revoke');
    Route::patch('/vending-machines/{vendingMachine}/devices/{device}/status', [DeviceAdministrationController::class, 'updateStatus'])
        ->middleware('perm:vending_machines,manage')->name('vending-machines.devices.status');
    Route::patch('/vending-machines/{vendingMachine}/devices/{device}/release-channel', [DeviceAdministrationController::class, 'updateReleaseChannel'])
        ->middleware('perm:vending_machines,manage')->name('vending-machines.devices.release-channel');

    Route::get('/dashboard/corporativo-reclutamiento', [CorporateRecruitmentDashboardController::class, 'index'])
        ->middleware('perm:dashboard,view')
        ->name('dashboard.corporativo-reclutamiento');
    Route::get('/dashboard/corporativo-reclutamiento/summary', [CorporateRecruitmentDashboardController::class, 'summary'])
        ->middleware('perm:dashboard,view')
        ->name('dashboard.corporativo-reclutamiento.summary');
    Route::get('/dashboard/corporativo-reclutamiento/export', [CorporateRecruitmentDashboardController::class, 'export'])
        ->middleware('perm:dashboard,export')
        ->name('dashboard.corporativo-reclutamiento.export');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/clocks', [ClockCatalogController::class, 'index'])->name('clocks.index');
    Route::get('/clocks/list', [ClockController::class, 'index'])->name('clocks.list');
    Route::post('/clocks', [ClockController::class, 'store'])->name('clocks.store');
    Route::put('/clocks/{clock}', [ClockController::class, 'update'])->name('clocks.update');
    Route::put('/clocks/{clock}/assign-unit', [ClockController::class, 'assignUnit'])->name('clocks.assign-unit');
    Route::post('/clocks/import', ClockImportController::class)->name('clocks.import');
    Route::get('/clocks/{clock}/logs', [ClockLogController::class, 'index'])->name('clocks.logs');

    Route::get('/employees', EmployeeCatalogPageController::class)->name('employees.index');
    Route::get('/employees/export', EmployeeCatalogExportController::class)
        ->middleware([
            'role:administrador,admin,superadmin',
            'perm.strict:employees,view',
        ])
        ->name('employees.catalog.export');

    Route::get('/attendance-cards', [AttendanceCardController::class, 'index'])
        ->middleware('perm:asistencias,view')
        ->name('attendance-cards.index');
    Route::get('/attendance-cards/employees/search', [AttendanceCardController::class, 'searchEmployees'])
        ->middleware('perm:asistencias,view')
        ->name('attendance-cards.employees.search');
    Route::get('/attendance-cards/export', [AttendanceCardController::class, 'export'])
        ->middleware('perm:asistencias,export')
        ->name('attendance-cards.export');

    Route::get('/companies', CompanyCatalogController::class)
        ->middleware('perm:companies,view')
        ->name('companies.index');
    Route::get('/companies/list', [CompanyController::class, 'index'])
        ->middleware('perm:companies,view')
        ->name('companies.list');
    Route::post('/companies', [CompanyController::class, 'store'])
        ->middleware('perm:companies,create')
        ->name('companies.store');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])
        ->middleware('perm:companies,update')
        ->name('companies.update');
    Route::put('/companies/{company}/toggle-status', [CompanyController::class, 'toggleStatus'])
        ->middleware('perm:companies,disable')
        ->name('companies.toggle-status');

    Route::get('/units', UnitCatalogController::class)
        ->middleware('perm:units,view')
        ->name('units.index');
    Route::get('/units/list', [UnitController::class, 'index'])
        ->middleware('perm:units,view')
        ->name('units.list');
    Route::get('/units/export', [UnitController::class, 'export'])
        ->middleware('perm:units,view')
        ->name('units.export');
    Route::get('/units/bulk-deactivation-preview', [UnitController::class, 'bulkDeactivationPreview'])
        ->middleware('perm:units,disable')
        ->name('units.bulk-deactivation-preview');
    Route::post('/units/bulk-deactivate-inactive', [UnitController::class, 'bulkDeactivateInactive'])
        ->middleware('perm:units,disable')
        ->name('units.bulk-deactivate-inactive');
    Route::post('/units', [UnitController::class, 'store'])
        ->middleware('perm:units,create')
        ->name('units.store');
    Route::get('/units/{unit}', [UnitController::class, 'show'])
        ->middleware('perm:units,view')
        ->name('units.show');
    Route::put('/units/{unit}', [UnitController::class, 'update'])
        ->middleware('perm:units,update')
        ->name('units.update');
    Route::put('/units/{unit}/toggle-status', [UnitController::class, 'toggleStatus'])
        ->middleware('perm:units,disable')
        ->name('units.toggle-status');

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', SettingsIndexController::class)
            ->middleware('perm:settings,view')
            ->name('index');

        Route::get('/roles', RolePageController::class)
            ->middleware('perm:settings,view')
            ->name('roles.page');

        Route::get('/roles/list', [SettingsRoleController::class, 'index'])
            ->middleware('perm:settings,view')
            ->name('roles.index');

        Route::post('/roles', [SettingsRoleController::class, 'store'])
            ->middleware('perm:settings,create')
            ->name('roles.store');

        Route::put('/roles/{role}', [SettingsRoleController::class, 'update'])
            ->middleware('perm:settings,update')
            ->name('roles.update');

        Route::delete('/roles/{role}', [SettingsRoleController::class, 'destroy'])
            ->middleware('perm:settings,delete')
            ->name('roles.destroy');

        Route::post('/roles/{role}/users', [RoleAssignmentController::class, 'store'])
            ->middleware('perm:settings,update')
            ->name('roles.users.store');

        Route::delete('/roles/{role}/users/{user}', [RoleAssignmentController::class, 'destroy'])
            ->middleware('perm:settings,update')
            ->name('roles.users.destroy');

        Route::get('/users', UserPageController::class)
            ->middleware('perm:users,view')
            ->name('users.page');

        Route::get('/audit', AuditPageController::class)
            ->middleware('perm:audit,view')
            ->name('audit.page');

        Route::get('/audit-cleanup', AuditCleanupPageController::class)
            ->middleware('perm:audit,manage')
            ->name('audit-cleanup.page');
    });

    Route::prefix('api/users')->group(function () {
        Route::get('/', [ApiUserController::class, 'index'])->middleware('perm:users,view');
        Route::post('/', [ApiUserController::class, 'store'])->middleware('perm:users,create');
        Route::put('{user}', [ApiUserController::class, 'update'])->middleware('perm:users,update');
        Route::patch('{user}/status', [ApiUserController::class, 'updateStatus'])->middleware('perm:users,disable');
    });

    Route::prefix('api/audit-logs')->middleware('perm:audit,view')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('{auditLog}', [AuditLogController::class, 'show']);
    });

    Route::prefix('settings/audit-logs')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->middleware('perm:audit,view');
        Route::get('{auditLog}', [AuditLogController::class, 'show'])->middleware('perm:audit,view');
        Route::post('/purge', [AuditLogController::class, 'purge'])->middleware('perm:audit,manage');
    });

    Route::prefix('api/audit-logs')->middleware('perm:audit,manage')->group(function () {
        Route::post('/purge', [AuditLogController::class, 'purge']);
    });

    Route::prefix('api/audit-cleanup')->middleware('perm:audit,manage')->group(function () {
        Route::get('/dashboard', [AuditCleanupController::class, 'dashboard']);
        Route::put('/settings', [AuditCleanupController::class, 'updateSettings']);
        Route::post('/preview', [AuditCleanupController::class, 'preview']);
        Route::post('/execute', [AuditCleanupController::class, 'execute']);
    });

    Route::prefix('admin/asistencias')->name('admin.asistencias.')->group(function () {
        Route::get('/', [AdminAttendanceController::class, 'index'])
            ->middleware('perm:asistencias,view')
            ->name('index');

        Route::get('/export', [AdminAttendanceController::class, 'export'])
            ->middleware('perm:asistencias,export')
            ->name('export');

        Route::get('/export-checks', [AdminAttendanceController::class, 'exportChecks'])
            ->middleware('perm:asistencias,export')
            ->name('export-checks');

        Route::post('/adjustments', [AdminAttendanceController::class, 'storeManualAdjustment'])
            ->middleware('perm:asistencias,edit')
            ->name('adjustments.store');

        Route::patch('/{attendance_record}/annul', [AdminAttendanceController::class, 'annul'])
            ->middleware('perm:asistencias,edit')
            ->name('annul');

        Route::get('/grouped-detail', [AdminAttendanceController::class, 'groupedDetail'])
            ->middleware('perm:asistencias,view')
            ->name('grouped-detail');

        Route::get('/{attendance_record}', [AdminAttendanceController::class, 'show'])
            ->middleware('perm:asistencias,view')
            ->name('show');
    });
});

require __DIR__.'/auth.php';
