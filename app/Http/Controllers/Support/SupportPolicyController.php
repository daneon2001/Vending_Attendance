<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportPolicyVersion;
use App\Services\Support\SupportAccess;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportPolicyService;
use Illuminate\Http\Request;

class SupportPolicyController extends Controller
{
    public function index(Request $request, SupportAccess $access)
    {
        $access->authorize(SupportActor::fromRequest($request), 'configure');

        return response()->json(['policies' => SupportPolicyVersion::query()->orderByDesc('version')->limit(30)->get()]);
    }

    public function store(Request $request, SupportPolicyService $policies)
    {
        return response()->json($policies->publish(SupportActor::fromRequest($request), $request->all()), 201);
    }
}
