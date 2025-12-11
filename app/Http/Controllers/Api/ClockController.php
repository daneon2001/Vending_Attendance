<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClockAssignmentRequest;
use App\Http\Requests\ClockRequest;
use App\Http\Resources\ClockResource;
use App\Models\Clock;
use Illuminate\Http\JsonResponse;

class ClockController extends Controller
{
    public function index(): JsonResponse
    {
        $clocks = Clock::with(['company', 'location'])
            ->orderBy('clock_name')
            ->get();

        return response()->json([
            'data' => ClockResource::collection($clocks)->resolve(),
        ]);
    }

    public function store(ClockRequest $request): JsonResponse
    {
        $clock = Clock::create($request->validated());

        return response()->json([
            'message' => 'Clock created',
            'data' => ClockResource::make($clock->load(['company', 'location']))->resolve(),
        ], 201);
    }

    public function update(ClockRequest $request, Clock $clock): JsonResponse
    {
        $clock->update($request->validated());

        return response()->json([
            'message' => 'Clock updated',
            'data' => ClockResource::make($clock->load(['company', 'location']))->resolve(),
        ]);
    }

    public function assign(ClockAssignmentRequest $request, Clock $clock): JsonResponse
    {
        $clock->update([
            'location_id' => $request->location_id,
        ]);

        return response()->json([
            'message' => 'Clock assigned to location',
            'data' => ClockResource::make($clock->load(['company', 'location']))->resolve(),
        ]);
    }
}
