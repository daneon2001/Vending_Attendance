<?php

use App\Http\Controllers\Support\SupportEvidenceController;
use App\Http\Controllers\Support\SupportWebController;
use App\Http\Middleware\LimitSupportUpload;
use App\Http\Middleware\SupportContext;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->name('support.')->middleware(['auth', 'verified', SupportContext::class.':user'])->group(function (): void {
    Route::get('tickets', [SupportWebController::class, 'index'])->name('tickets.index');
    Route::get('options', [SupportWebController::class, 'options'])->name('options');
    Route::get('notifications', [\App\Http\Controllers\Support\SupportNotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [\App\Http\Controllers\Support\SupportNotificationController::class, 'read'])->name('notifications.read');
    Route::get('policies', [\App\Http\Controllers\Support\SupportPolicyController::class, 'index'])->name('policies.index');
    Route::post('policies', [\App\Http\Controllers\Support\SupportPolicyController::class, 'store'])->middleware('throttle:support-write')->name('policies.store');
    Route::get('summary', [SupportWebController::class, 'summary'])->name('summary');
    Route::post('tickets', [SupportWebController::class, 'store'])->middleware('throttle:support-write')->name('tickets.store');
    Route::get('tickets/{ticket}', [SupportWebController::class, 'show'])->name('tickets.show');
    Route::post('tickets/{ticket}/comments', [SupportWebController::class, 'comment'])->middleware('throttle:support-write')->name('tickets.comment');
    Route::post('tickets/{ticket}/assign', [SupportWebController::class, 'assign'])->middleware('throttle:support-write')->name('tickets.assign');
    Route::post('tickets/{ticket}/transition', [SupportWebController::class, 'transition'])->middleware('throttle:support-write')->name('tickets.transition');
    Route::get('verifications', [SupportWebController::class, 'verifications'])->name('verifications.index');
    Route::post('verifications', [\App\Http\Controllers\Support\SupportVerificationController::class, 'store'])->middleware('throttle:support-upload')->name('verifications.store');
    Route::post('tickets/{ticket}/evidence/file', [SupportEvidenceController::class, 'webUpload'])->middleware([LimitSupportUpload::class, 'throttle:support-upload'])->name('evidence.upload');
    Route::get('tickets/{ticket}/evidence/{evidence}/download', [SupportEvidenceController::class, 'download'])->name('evidence.download');
    Route::get('tickets/{ticket}/evidence/{evidence}/thumbnail', [SupportEvidenceController::class, 'thumbnail'])->name('evidence.thumbnail');
});
