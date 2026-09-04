<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmployeeMachineAssignmentResource;
use App\Http\Resources\Api\V1\MachineGeofenceResource;
use App\Http\Resources\Api\V1\VendingMachineResource;
use App\Models\VendingMachine;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VendingMachineController extends Controller
{
    public function show(VendingMachine $vendingMachine): VendingMachineResource
    {
        return new VendingMachineResource($vendingMachine->load('activeGeofence'));
    }

    public function geofence(VendingMachine $vendingMachine): MachineGeofenceResource
    {
        $geofence = $vendingMachine->geofences()->effectiveAt(now())->firstOrFail();

        return new MachineGeofenceResource($geofence);
    }

    public function assignments(VendingMachine $vendingMachine): AnonymousResourceCollection
    {
        $assignments = $vendingMachine->assignments()
            ->with('employee')
            ->orderByDesc('valid_from')
            ->get();

        return EmployeeMachineAssignmentResource::collection($assignments);
    }
}
