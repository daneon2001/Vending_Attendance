<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditCleanupService;
use Inertia\Inertia;
use Inertia\Response;

class AuditCleanupPageController extends Controller
{
    public function __invoke(AuditCleanupService $service): Response
    {
        return Inertia::render('Settings/AuditCleanup/Index', [
            'settings' => $service->currentSettings(),
            'dashboard' => $service->dashboard(),
        ]);
    }
}
