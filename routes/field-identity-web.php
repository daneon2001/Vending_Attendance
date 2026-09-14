<?php

use App\Http\Controllers\FieldIdentity\DeviceAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('administration/identity')->name('field-identity.admin.')
    ->middleware(['auth', 'verified'])->group(function (): void {
        Route::get('/', [DeviceAdminController::class, 'index'])->name('index');
        Route::post('{uuid}/revoke', [DeviceAdminController::class, 'revoke'])
            ->whereUuid('uuid')->middleware('throttle:20,1')->name('revoke');
    });
