<?php

namespace App\Http\Controllers\Vending;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vending\RevokeEmployeeMachineAssignmentRequest;
use App\Http\Requests\Vending\StoreEmployeeMachineAssignmentRequest;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;
use App\Services\Vending\MachineAssignmentService;
use Illuminate\Http\RedirectResponse;

class EmployeeMachineAssignmentController extends Controller
{
    public function store(StoreEmployeeMachineAssignmentRequest $request, VendingMachine $vendingMachine, MachineAssignmentService $service): RedirectResponse
    {
        $service->create($vendingMachine, $request->validated(), $request->user()?->id);

        return back()->with('success', 'Empleado asignado a la máquina.');
    }

    public function revoke(
        RevokeEmployeeMachineAssignmentRequest $request,
        VendingMachine $vendingMachine,
        EmployeeMachineAssignment $assignment,
        MachineAssignmentService $service,
    ): RedirectResponse {
        abort_unless($assignment->vending_machine_id === $vendingMachine->id, 404);
        $service->revoke($assignment, $request->user()?->id, $request->validated('reason'));

        return back()->with('success', 'Asignación revocada y conservada en el historial.');
    }
}
