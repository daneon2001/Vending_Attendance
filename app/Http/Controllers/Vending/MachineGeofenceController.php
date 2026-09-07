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
        $attributes = $request->validated();
        $expectedVersion = $attributes['expected_config_version'] ?? null;
        unset($attributes['expected_config_version']);
        $service->create($vendingMachine, $attributes, $request->user()?->id, $expectedVersion);

        return back()->with('success', 'Versión de geocerca creada.');
    }

    public function activate(Request $request, VendingMachine $vendingMachine, MachineGeofence $geofence, MachineGeofenceService $service): RedirectResponse
    {
        abort_unless($geofence->vending_machine_id === $vendingMachine->id, 404);
        $service->activate($geofence, $request->user()?->id, $this->expectedVersion($request));

        return back()->with('success', 'Geocerca activada; se conservó la versión anterior.');
    }

    public function deactivate(Request $request, VendingMachine $vendingMachine, MachineGeofence $geofence, MachineGeofenceService $service): RedirectResponse
    {
        abort_unless($geofence->vending_machine_id === $vendingMachine->id, 404);
        $service->deactivate($geofence, $this->expectedVersion($request));

        return back()->with('success', 'Geocerca desactivada. La configuración se enviará mediante la sincronización habitual.');
    }

    public function verifyLocation(Request $request, VendingMachine $vendingMachine, MachineGeofenceService $service): RedirectResponse
    {
        $validated = $request->validate(['confirmed' => ['required', 'accepted'], 'expected_config_version' => ['required', 'integer', 'min:1']]);
        $service->verifyLocation($vendingMachine, $validated['expected_config_version']);

        return back()->with('success', 'Ubicación registrada verificada. El centro operacional no fue modificado.');
    }

    private function expectedVersion(Request $request): ?int
    {
        return $request->validate(['expected_config_version' => ['sometimes', 'integer', 'min:1']])['expected_config_version'] ?? null;
    }
}
