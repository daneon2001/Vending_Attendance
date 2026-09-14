<?php

namespace App\Http\Controllers\FieldIdentity;

use App\Http\Controllers\Controller;
use App\Services\FieldIdentity\FieldMobileSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FieldMobileSessionController extends Controller
{
    public function store(Request $request, FieldMobileSession $sessions): JsonResponse
    {
        $input = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:256']);

        return response()->json($sessions->login($input['email'], $input['password'], $request->ip()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['signed_out' => true])->header('Cache-Control', 'no-store, private');
    }
}
