<?php

namespace App\Http\Controllers\Vending;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vending\StoreMachineGeofenceRequest;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use App\Services\Vending\MachineGeofenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MachineGeofenceController extends Controller
{
    public function store(StoreMachineGeofenceRequest $request, VendingMachine $vendingMachine, MachineGeofenceService $service): RedirectResponse
    {
        $service->create($vendingMachine, $request->validated(), $request->user()?->id);

        return back()->with('success', 'Versión de geocerca creada.');
    }

    public function activate(Request $request, VendingMachine $vendingMachine, MachineGeofence $geofence, MachineGeofenceService $service): RedirectResponse
    {
        abort_unless($geofence->vending_machine_id === $vendingMachine->id, 404);
        $service->activate($geofence, $request->user()?->id);

        return back()->with('success', 'Geocerca activada; la versión anterior quedó superseded.');
    }
}
