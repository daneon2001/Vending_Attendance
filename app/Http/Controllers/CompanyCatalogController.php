<?php

namespace App\Http\Controllers;

use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyCatalogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $perPage = $request->integer('per_page', 12);

        $companies = Company::query()
            ->withCount([
                'locations as units_count',
                'clocks',
            ])
            ->orderBy('name')
            ->paginate($perPage);

        return Inertia::render('Companies/Index', [
            'initialCompanies' => [
                'data' => CompanyResource::collection($companies)->resolve(),
                'meta' => [
                    'current_page' => $companies->currentPage(),
                    'last_page' => $companies->lastPage(),
                    'per_page' => $companies->perPage(),
                    'total' => $companies->total(),
                    'from' => $companies->firstItem(),
                    'to' => $companies->lastItem(),
                ],
            ],
        ]);
    }
}
