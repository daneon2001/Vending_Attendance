<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogSyncController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
        ]);

        // Endpoint pensado para la app on-prem (Python) que consume catalogos biometricos.
        $locationId = $validated['location_id'] ?? null;

        $employeesQuery = Employee::query()->whereIn('status', ['A', 'active']);
        if ($locationId) {
            $employeesQuery->where('base_location_id', $locationId);
        }

        $clocksQuery = Clock::query();
        if ($locationId) {
            $clocksQuery->where('location_id', $locationId);
        }

        $locationsQuery = Location::query();
        if ($locationId) {
            $locationsQuery->where('id', $locationId);
        }

        $company = null;
        if ($locationId) {
            $company = Location::query()->with('company')->find($locationId)?->company;
        }

        if (! $company) {
            $company = Company::query()->orderBy('name')->first();
        }

        return response()->json([
            'employees' => $employeesQuery->orderBy('full_name')->get(),
            'clocks' => $clocksQuery->orderBy('clock_name')->get(),
            'locations' => $locationsQuery->orderBy('name')->get(),
            'company' => $company,
            'version' => now()->format('YmdHis'),
        ]);
    }
}
