<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmployeeMachineAssignmentResource;
use App\Models\Employee;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeVendingMachineController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return EmployeeMachineAssignmentResource::collection(
            $employee->machineAssignments()
                ->with('vendingMachine.activeGeofence')
                ->active()
                ->effectiveAt(now())
                ->orderByDesc('valid_from')
                ->get()
        );
    }
}
