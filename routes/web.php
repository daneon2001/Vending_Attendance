<?php

use App\Http\Controllers\ClockCatalogController;
use App\Http\Controllers\ClockController;
use App\Http\Controllers\ClockImportController;
use App\Http\Controllers\ClockLogController;
use App\Http\Controllers\ProfileController;
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
});

require __DIR__.'/auth.php';
