<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportVerificationService;
use Illuminate\Http\Request;

class SupportVerificationController extends Controller
{
    public function store(Request $request, SupportVerificationService $verification)
    {
        return response()->json($verification->capture(SupportActor::fromRequest($request), $request->all()), 201);
    }
}
