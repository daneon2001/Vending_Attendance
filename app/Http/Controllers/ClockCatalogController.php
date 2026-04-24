<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClockResource;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Location;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClockCatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = $request->integer('per_page', 12);

        $query = Clock::query()
            ->with(['company', 'location']);

        $this->applyCatalogFilters($query, $request);

        $clocks = $query
            ->orderBy('clock_name')
            ->paginate($perPage)
            ->withQueryString();

        $locations = Location::select('id', 'name', 'code')->orderBy('name')->get();
        $companies = Company::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Clocks/Index', [
            'initialClocks' => [
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
            ],
            'locations' => $locations,
            'companies' => $companies,
            'perPage' => $perPage,
            'filters' => [
                'q' => trim((string) $request->input('q', '')),
                'company_id' => $request->input('company_id'),
                'location_id' => $request->input('location_id'),
                'status' => $request->input('status'),
                'monitoring_status' => $request->input('monitoring_status'),
                'program_status' => $request->input('program_status'),
                'per_page' => $perPage,
            ],
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
                $query->where('monitoring_status', $monitoringStatus);
            }
        }

        if ($request->filled('program_status')) {
            $programStatus = trim((string) $request->input('program_status'));
            if ($programStatus !== '') {
                $query->where('program_status', $programStatus);
            }
        }
    }
}
