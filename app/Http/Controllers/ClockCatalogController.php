<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClockResource;
use App\Models\Company;
use App\Models\Location;
use App\Services\ClockCatalogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClockCatalogController extends Controller
{
    public function __construct(
        protected ClockCatalogService $clockCatalogService
    ) {
    }

    public function index(Request $request): Response
    {
        $perPage = $request->integer('per_page', 12);
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'company_id' => $request->input('company_id'),
            'location_id' => $request->input('location_id'),
            'status' => $request->input('status'),
            'monitoring_status' => $request->input('monitoring_status'),
            'program_status' => $request->input('program_status'),
        ];

        $clocks = $this->clockCatalogService->paginate($filters, $perPage);
        $summary = $this->clockCatalogService->summarize($filters);

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
            'summary' => $summary,
            'locations' => $locations,
            'companies' => $companies,
            'perPage' => $perPage,
            'filters' => [
                ...$filters,
                'per_page' => $perPage,
            ],
        ]);
    }
}
