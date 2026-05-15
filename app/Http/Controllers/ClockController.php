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
        $query = Clock::query()
            ->with(['company', 'location']);

        $this->applyCatalogFilters($query, $request);

        $clocks = $query
            ->orderBy('clock_name')
            ->paginate($request->integer('per_page', 12))
            ->withQueryString();

        return response()->json([
            'data' => ClockResource::collection($clocks)->resolve(),
            'meta' => [
                'current_page' => $clocks->currentPage(),
                'last_page' => $clocks->lastPage(),
                'per_page' => $clocks->perPage(),
                'total' => $clocks->total(),
                'next_page_url' => $clocks->nextPageUrl(),
                'prev_page_url' => $clocks->previousPageUrl(),
                'from' => $clocks->firstItem(),
                'to' => $clocks->lastItem(),
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

    private function applyCatalogFilters($query, Request $request): void
    {
        $query->when($request->filled('q'), function ($clockQuery) use ($request): void {
            $q = trim((string) $request->input('q'));
            if ($q === '') {
                return;
            }

            $clockQuery->where(function ($subQuery) use ($q): void {
                $subQuery
                    ->where('clock_name', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%")
                    ->orWhere('ip_address', 'like', "%{$q}%")
                    ->orWhere('last_seen_ip', 'like', "%{$q}%")
                    ->orWhereHas('company', fn ($companyQuery) => $companyQuery->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('location', fn ($locationQuery) => $locationQuery->where('name', 'like', "%{$q}%"));
            });
        });

        if ($request->filled('company_id') && is_numeric($request->input('company_id'))) {
            $query->where('company_id', (int) $request->input('company_id'));
        }

        if ($request->filled('location_id')) {
            $locationFilter = (string) $request->input('location_id');
            if ($locationFilter === 'unassigned') {
                $query->whereNull('location_id');
            } elseif (is_numeric($locationFilter)) {
                $query->where('location_id', (int) $locationFilter);
            }
        }

        if ($request->filled('status')) {
            $status = (string) $request->input('status');
            if (in_array($status, ['0', '1'], true)) {
                $query->where('status', (int) $status);
            }
        }

        if ($request->filled('monitoring_status')) {
            $monitoringStatus = trim((string) $request->input('monitoring_status'));
            if ($monitoringStatus !== '') {
                $query->whereConnectionStatus($monitoringStatus);
            }
        }

        if ($request->filled('program_status')) {
            $programStatus = trim((string) $request->input('program_status'));
            if ($programStatus !== '') {
                $query->whereOnPremProgramStatus($programStatus);
            }
        }
    }
}
