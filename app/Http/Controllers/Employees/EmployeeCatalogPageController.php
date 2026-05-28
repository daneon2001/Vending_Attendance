<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Services\Employees\EmployeeCatalogQueryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeCatalogPageController extends Controller
{
    public function __invoke(Request $request, EmployeeCatalogQueryService $catalogQueryService): Response
    {
        return Inertia::render('Employees/EmployeesCatalog', [
            'locations' => $catalogQueryService->listLocations(),
            'initialFilters' => [
                'status' => (string) $request->query('status', ''),
                'q' => (string) $request->query('q', ''),
                'fingerprint' => (string) $request->query('fingerprint', ''),
                'face' => (string) $request->query('face', ''),
                'sync_ready' => $request->boolean('sync_ready'),
                'location_id' => (string) $request->query('location_id', ''),
                'page' => $request->integer('page', 1),
                'per_page' => $request->integer('per_page', 15),
            ],
        ]);
    }
}
