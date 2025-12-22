<?php

use App\Http\Controllers\ClockCatalogController;
use App\Http\Controllers\ClockController;
use App\Http\Controllers\ClockImportController;
use App\Http\Controllers\ClockLogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\UserController as ApiUserController;
use App\Http\Controllers\Settings\AuditPageController;
use App\Http\Controllers\Settings\RoleAssignmentController;
use App\Http\Controllers\Settings\RoleController as SettingsRoleController;
use App\Http\Controllers\Settings\RolePageController;
use App\Http\Controllers\Settings\SettingsIndexController;
use App\Http\Controllers\Settings\UserPageController;
use App\Http\Controllers\UnitCatalogController;
use App\Http\Controllers\UnitController;
use App\Models\Location;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    $locations = Location::query()
        ->select('id', 'name', 'code')
        ->orderBy('name')
        ->get();

    return Inertia::render('Dashboard', [
        'locations' => $locations,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

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

    Route::get('/employees', function () {
        return Inertia::render('Employees/EmployeesCatalog');
    })->name('employees.index');

    Route::get('/units', UnitCatalogController::class)->name('units.index');
    Route::get('/units/list', [UnitController::class, 'index'])->name('units.list');
    Route::post('/units', [UnitController::class, 'store'])->name('units.store');
    Route::get('/units/{unit}', [UnitController::class, 'show'])->name('units.show');
    Route::put('/units/{unit}', [UnitController::class, 'update'])->name('units.update');
    Route::put('/units/{unit}/toggle-status', [UnitController::class, 'toggleStatus'])->name('units.toggle-status');

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
});

require __DIR__.'/auth.php';
