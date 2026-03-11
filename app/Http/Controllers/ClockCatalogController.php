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

        $clocks = Clock::with(['company', 'location'])
            ->orderBy('clock_name')
            ->paginate($perPage);

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
                ],
            ],
            'locations' => $locations,
            'companies' => $companies,
            'perPage' => $perPage,
        ]);
    }
}
