<?php

namespace App\Http\Controllers\Api\FortiaMock;

use App\Http\Controllers\Controller;
use App\Services\FortiaMock\FortiaMockSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FortiaMockSyncController extends Controller
{
    public function __construct(protected FortiaMockSyncService $syncService)
    {
    }

    public function sync(Request $request): JsonResponse
    {
        $filters = [];
        if ($request->filled('company_id')) {
            $filters['company_id'] = (int) $request->input('company_id');
        }

        $summary = $this->syncService->syncIncremental($filters);

        return response()->json($summary);
    }
}
