<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClockLogFilterRequest;
use App\Http\Resources\ClockLogResource;
use App\Models\Clock;
use Illuminate\Http\JsonResponse;

class ClockLogController extends Controller
{
    public function index(ClockLogFilterRequest $request, Clock $clock): JsonResponse
    {
        $query = $clock->logs()->latest('occurred_at');

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', 'like', '%'.$request->event_type.'%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('occurred_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('occurred_at', '<=', $request->date_to);
        }

        $logs = $query->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => ClockLogResource::collection($logs)->resolve(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'next_page_url' => $logs->nextPageUrl(),
                'prev_page_url' => $logs->previousPageUrl(),
            ],
        ]);
    }
}
