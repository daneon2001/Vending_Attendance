<?php

namespace App\Http\Controllers;

use App\Http\Resources\UnitResource;
use App\Models\Company;
use App\Services\Units\UnitCatalogQueryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitCatalogController extends Controller
{
    public function __invoke(Request $request, UnitCatalogQueryService $unitCatalogQueryService): Response
    {
        $perPage = $request->integer('per_page', 12);

        $units = $unitCatalogQueryService->buildFilteredListQuery([])
            ->orderBy('name')
            ->paginate($perPage);

        $companies = Company::select('id', 'name')->orderBy('name')->get();
        $summary = $unitCatalogQueryService->buildSummary();

        return Inertia::render('Units/Index', [
            'initialUnits' => [
                'data' => UnitResource::collection($units)->resolve(),
                'meta' => [
                    'current_page' => $units->currentPage(),
                    'last_page' => $units->lastPage(),
                    'per_page' => $units->perPage(),
                    'total' => $units->total(),
                    'from' => $units->firstItem(),
                    'to' => $units->lastItem(),
                ],
            ],
            'summary' => $summary,
            'filteredMeta' => [
                'filtered_total' => $units->total(),
                'current_page_count' => $units->count(),
            ],
            'companies' => $companies,
        ]);
    }
}
