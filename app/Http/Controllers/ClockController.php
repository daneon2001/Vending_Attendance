<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClockAssignmentRequest;
use App\Http\Requests\ClockRequest;
use App\Http\Resources\ClockResource;
use App\Models\Clock;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ClockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clocks = Clock::with(['company', 'location'])
            ->orderBy('clock_name')
            ->paginate($request->integer('per_page', 12));

        return response()->json([
            'data' => ClockResource::collection($clocks)->resolve(),
            'meta' => [
                'current_page' => $clocks->currentPage(),
                'last_page' => $clocks->lastPage(),
                'per_page' => $clocks->perPage(),
                'total' => $clocks->total(),
                'next_page_url' => $clocks->nextPageUrl(),
                'prev_page_url' => $clocks->previousPageUrl(),
            ],
        ]);
    }

    public function store(ClockRequest $request): JsonResponse
    {
        $clock = Clock::create($request->validated());

        AuditLogger::log(
            'clocks.created',
            $clock,
            'Reloj creado',
            [
                'attributes' => Arr::except($request->validated(), []),
            ]
        );

        return response()->json([
            'message' => 'Reloj creado correctamente',
            'data' => ClockResource::make(
                $clock->load(['company', 'location'])
            )->resolve(),
        ], 201);
    }

    public function update(ClockRequest $request, Clock $clock): JsonResponse
    {
        $before = $clock->toArray();
        $clock->update($request->validated());

        AuditLogger::log(
            'clocks.updated',
            $clock,
            'Reloj actualizado',
            [
                'before' => Arr::only($before, array_keys($request->validated())),
                'after' => Arr::only($clock->toArray(), array_keys($request->validated())),
            ]
        );

        return response()->json([
            'message' => 'Reloj actualizado',
            'data' => ClockResource::make(
                $clock->load(['company', 'location'])
            )->resolve(),
        ]);
    }

    public function assignUnit(ClockAssignmentRequest $request, Clock $clock): JsonResponse
    {
        $beforeLocation = $clock->location_id;
        $clock->update([
            'location_id' => $request->location_id,
        ]);

        AuditLogger::log(
            'clocks.location_assigned',
            $clock,
            'Unidad asignada al reloj',
            [
                'before_location' => $beforeLocation,
                'after_location' => $clock->location_id,
            ]
        );

        return response()->json([
            'message' => 'Unidad asignada',
            'data' => ClockResource::make(
                $clock->load(['company', 'location'])
            )->resolve(),
        ]);
    }
}
