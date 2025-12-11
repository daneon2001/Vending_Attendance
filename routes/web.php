<?php

use App\Http\Controllers\ClockCatalogController;
use App\Http\Controllers\ClockController;
use App\Http\Controllers\ClockImportController;
use App\Http\Controllers\ClockLogController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/clocks', [ClockCatalogController::class, 'index'])->name('clocks.index');
    Route::post('/clocks', [ClockController::class, 'store'])->name('clocks.store');
    Route::put('/clocks/{clock}', [ClockController::class, 'update'])->name('clocks.update');
    Route::put('/clocks/{clock}/assign-unit', [ClockController::class, 'assignUnit'])->name('clocks.assign-unit');
    Route::post('/clocks/import', ClockImportController::class)->name('clocks.import');
    Route::get('/clocks/{clock}/logs', [ClockLogController::class, 'index'])->name('clocks.logs');
});

require __DIR__.'/auth.php';
