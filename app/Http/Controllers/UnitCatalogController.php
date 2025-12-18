<?php

namespace App\Http\Controllers;

use App\Http\Resources\UnitResource;
use App\Models\Company;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitCatalogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $perPage = $request->integer('per_page', 12);

        $units = Unit::with('company:id,name')
            ->withCount('clocks')
            ->orderBy('name')
            ->paginate($perPage);

        $companies = Company::select('id', 'name')->orderBy('name')->get();

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
            'companies' => $companies,
        ]);
    }
}
