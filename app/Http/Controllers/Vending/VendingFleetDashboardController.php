<?php

namespace App\Http\Controllers\Vending;

use App\Http\Controllers\Controller;
use App\Services\Vending\VendingFleetOperationsService;
use Inertia\Inertia;
use Inertia\Response;

class VendingFleetDashboardController extends Controller
{
    public function __invoke(VendingFleetOperationsService $operations): Response
    {
        return Inertia::render('VendingFleet/Dashboard', $operations->dashboard());
    }
}
