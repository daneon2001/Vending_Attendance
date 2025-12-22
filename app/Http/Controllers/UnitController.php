<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitDetailResource;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class UnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Unit::query()
            ->with('company:id,name')
            ->withCount('clocks');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->boolean('status') ? 1 : 0);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $units = $query
            ->orderBy('name')
            ->paginate($request->integer('per_page', 12));

        return response()->json([
            'data' => UnitResource::collection($units)->resolve(),
            'meta' => [
                'current_page' => $units->currentPage(),
                'last_page' => $units->lastPage(),
                'per_page' => $units->perPage(),
                'total' => $units->total(),
                'next_page_url' => $units->nextPageUrl(),
                'prev_page_url' => $units->previousPageUrl(),
                'from' => $units->firstItem(),
                'to' => $units->lastItem(),
            ],
        ]);
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        $unit = Unit::create($request->validated());

        AuditLogger::log(
            'units.created',
            $unit,
            'Sucursal creada',
            [
                'attributes' => $request->validated(),
            ]
        );

        return response()->json([
            'message' => 'Sucursal creada correctamente',
            'data' => UnitResource::make($unit->load('company'))->resolve(),
        ], 201);
    }

    public function show(Unit $unit): JsonResponse
    {
        $unit->load(['company', 'clocks' => function ($query) {
            $query->select('id', 'location_id', 'clock_name', 'ip_address', 'monitoring_status', 'status', 'last_heartbeat_at');
        }]);

        return response()->json([
            'data' => UnitDetailResource::make($unit)->resolve(),
        ]);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        $before = $unit->only(array_keys($request->validated()));
        $unit->update($request->validated());

        AuditLogger::log(
            'units.updated',
            $unit,
            'Sucursal actualizada',
            [
                'before' => $before,
                'after' => Arr::only($unit->toArray(), array_keys($request->validated())),
            ]
        );

        return response()->json([
            'message' => 'Sucursal actualizada',
            'data' => UnitResource::make($unit->load('company'))->resolve(),
        ]);
    }

    public function toggleStatus(Unit $unit): JsonResponse
    {
        $previous = $unit->status;
        $unit->update([
            'status' => $unit->status ? 0 : 1,
        ]);

        AuditLogger::log(
            'units.status_changed',
            $unit,
            $unit->status ? 'Sucursal desactivada' : 'Sucursal activada',
            [
                'before' => $previous ? 'activa' : 'inactiva',
                'after' => $unit->status ? 'activa' : 'inactiva',
            ]
        );

        return response()->json([
            'message' => $unit->status ? 'Sucursal activada' : 'Sucursal desactivada',
            'data' => UnitResource::make($unit->load('company'))->resolve(),
        ]);
    }
}
