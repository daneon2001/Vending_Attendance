<?php

use App\Http\Controllers\Support\SupportEvidenceController;
use App\Http\Controllers\Support\SupportTicketController;
use App\Http\Middleware\LimitSupportUpload;
use App\Http\Middleware\SupportContext;
use App\Http\Middleware\SupportIntegrationAuthentication;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

$ticketRoutes = function (): void {
    Route::get('tickets', [SupportTicketController::class, 'index']);
    Route::get('changes', [SupportTicketController::class, 'changes']);
    Route::get('tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('tickets', [SupportTicketController::class, 'store'])->middleware('throttle:support-write');
    Route::post('tickets/{ticket}/comments', [SupportTicketController::class, 'comment'])->middleware('throttle:support-write');
    Route::post('tickets/{ticket}/assign', [SupportTicketController::class, 'assign'])->middleware('throttle:support-write');
    Route::post('tickets/{ticket}/transition', [SupportTicketController::class, 'transition'])->middleware('throttle:support-write');
    Route::post('tickets/{ticket}/evidence', [SupportEvidenceController::class, 'initiate'])->middleware('throttle:support-write');
    Route::get('tickets/{ticket}/evidence/{evidence}', [SupportEvidenceController::class, 'show']);
    Route::get('tickets/{ticket}/evidence/{evidence}/download', [SupportEvidenceController::class, 'download']);
    Route::get('tickets/{ticket}/evidence/{evidence}/thumbnail', [SupportEvidenceController::class, 'thumbnail']);
};

Route::post('v1/device/support/tickets/{ticket}/evidence/{evidence}/content', [SupportEvidenceController::class, 'upload'])
    ->middleware([LimitSupportUpload::class, 'device.hmac:vending', SupportContext::class.':device', 'throttle:support-upload']);

Route::prefix('v1/device/support')->middleware(['device.hmac:vending', SupportContext::class.':device', 'throttle:support-read'])
    ->group(function () use ($ticketRoutes): void {
        Route::get('context', [SupportTicketController::class, 'context']);
        Route::post('verifications', [\App\Http\Controllers\Support\SupportVerificationController::class, 'store'])->middleware('throttle:support-upload');
        $ticketRoutes();
    });

Route::prefix('v1/support/integration')->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware([SupportIntegrationAuthentication::class, 'throttle:support-read'])->group($ticketRoutes);
