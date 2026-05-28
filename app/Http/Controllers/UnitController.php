<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitDetailResource;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Services\Audit\AuditLogger;
use App\Services\Units\UnitCatalogQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class UnitController extends Controller
{
    public function index(Request $request, UnitCatalogQueryService $unitCatalogQueryService): JsonResponse
    {
        $filters = [
            'company_id' => $request->input('company_id'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ];

        $units = $unitCatalogQueryService->buildFilteredListQuery($filters)
            ->orderBy('name')
            ->paginate($request->integer('per_page', 12));
        $summary = $unitCatalogQueryService->buildSummary();

        return response()->json([
            'data' => UnitResource::collection($units)->resolve(),
            'summary' => $summary,
            'filtered_meta' => [
                'filtered_total' => $units->total(),
                'current_page_count' => $units->count(),
            ],
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
            'Unidad creada',
            [
                'attributes' => $request->validated(),
            ]
        );

        return response()->json([
            'message' => 'Unidad creada correctamente',
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
            'Unidad actualizada',
            [
                'before' => $before,
                'after' => Arr::only($unit->toArray(), array_keys($request->validated())),
            ]
        );

        return response()->json([
            'message' => 'Unidad actualizada',
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
            $unit->status ? 'Unidad activada' : 'Unidad desactivada',
            [
                'before' => $previous ? 'activa' : 'inactiva',
                'after' => $unit->status ? 'activa' : 'inactiva',
            ]
        );

        return response()->json([
            'message' => $unit->status ? 'Unidad activada' : 'Unidad desactivada',
            'data' => UnitResource::make($unit->load('company'))->resolve(),
        ]);
    }
}
