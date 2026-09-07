<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportNotificationService;
use Illuminate\Http\Request;

class SupportNotificationController extends Controller
{
    public function index(Request $request, SupportNotificationService $notifications)
    {
        return response()->json($notifications->feed(SupportActor::fromRequest($request)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification, SupportNotificationService $notifications)
    {
        $notifications->markRead(SupportActor::fromRequest($request), $notification);

        return response()->noContent();
    }
}
