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
        $units = Unit::with('company:id,name')
            ->withCount('clocks')
            ->orderBy('name')
            ->take(12)
            ->get();

        $companies = Company::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Units/Index', [
            'units' => UnitResource::collection($units)->resolve(),
            'companies' => $companies,
        ]);
    }
}
