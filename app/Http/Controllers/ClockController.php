<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClockAssignmentRequest;
use App\Http\Requests\ClockRequest;
use App\Http\Resources\ClockResource;
use App\Models\Clock;
use Illuminate\Http\JsonResponse;

class ClockController extends Controller
{
    public function store(ClockRequest $request): JsonResponse
    {
        $clock = Clock::create($request->validated());

        return response()->json([
            'message' => 'Reloj creado correctamente',
            'data' => ClockResource::make(
                $clock->load(['company', 'location'])
            )->resolve(),
        ], 201);
    }

    public function update(ClockRequest $request, Clock $clock): JsonResponse
    {
        $clock->update($request->validated());

        return response()->json([
            'message' => 'Reloj actualizado',
            'data' => ClockResource::make(
                $clock->load(['company', 'location'])
            )->resolve(),
        ]);
    }

    public function assignUnit(ClockAssignmentRequest $request, Clock $clock): JsonResponse
    {
        $clock->update([
            'location_id' => $request->location_id,
        ]);

        return response()->json([
            'message' => 'Unidad asignada',
            'data' => ClockResource::make(
                $clock->load(['company', 'location'])
            )->resolve(),
        ]);
    }
}
