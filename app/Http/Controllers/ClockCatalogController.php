<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClockResource;
use App\Models\Clock;
use App\Models\Location;
use Inertia\Inertia;
use Inertia\Response;

class ClockCatalogController extends Controller
{
    public function index(): Response
    {
        $clocks = Clock::with(['company', 'location'])
            ->orderBy('clock_name')
            ->get();

        $locations = Location::select('id', 'name', 'code')->orderBy('name')->get();

        return Inertia::render('Clocks/Index', [
            'clocks' => ClockResource::collection($clocks)->resolve(),
            'locations' => $locations,
        ]);
    }
}
