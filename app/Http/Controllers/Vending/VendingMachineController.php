<?php

namespace App\Http\Controllers\Vending;

use App\Enums\DeviceStatus;
use App\Enums\Vending\AssignmentType;
use App\Enums\Vending\CoordinateSource;
use App\Enums\Vending\GeofenceStatus;
use App\Enums\Vending\VendingMachineStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vending\StoreVendingMachineRequest;
use App\Http\Requests\Vending\UpdateVendingMachineRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceProvisioningToken;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use App\Services\Vending\DeviceManifestStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendingMachineController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'municipality', 'locality']);
        $machines = VendingMachine::query()
            ->with('activeGeofence')
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query->active()->effectiveAt(now())])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($nested) use ($search): void {
                $nested->where('machine_code', 'like', "%{$search}%")
                    ->orWhere('operational_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('sybi_id', 'like', "%{$search}%");
            }))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['municipality'] ?? null, fn ($query, $value) => $query->where('municipality', $value))
            ->when($filters['locality'] ?? null, fn ($query, $value) => $query->where('locality', $value))
            ->orderBy('machine_code')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('VendingMachines/Index', [
            'machines' => $machines,
            'filters' => $filters,
            'statuses' => VendingMachineStatus::values(),
            'coordinateSources' => CoordinateSource::values(),
            'municipalities' => VendingMachine::query()->whereNotNull('municipality')->distinct()->orderBy('municipality')->pluck('municipality'),
            'localities' => VendingMachine::query()->whereNotNull('locality')->distinct()->orderBy('locality')->pluck('locality'),
        ]);
    }

    public function store(StoreVendingMachineRequest $request): RedirectResponse
    {
        $machine = VendingMachine::query()->create($request->validated());

        return redirect()->route('vending-machines.show', $machine)->with('success', 'Máquina vending creada.');
    }

    public function show(VendingMachine $vendingMachine, DeviceManifestStatusService $manifestStatuses): Response
    {
        $vendingMachine->load([
            'geofences' => fn ($query) => $query->orderByDesc('version'),
            'assignments' => fn ($query) => $query->with('employee')->orderByDesc('valid_from'),
            'devices' => fn ($query) => $query->orderByDesc('id'),
            'provisioningTokens' => fn ($query) => $query->latest('id')->limit(20),
        ]);

        $assignmentIds = $vendingMachine->assignments->pluck('id');
        $geofenceIds = $vendingMachine->geofences->pluck('id');
        $deviceIds = $vendingMachine->devices->pluck('id');
        $provisioningTokenIds = $vendingMachine->provisioningTokens->pluck('id');
        $auditLogs = AuditLog::query()
            ->where(function ($query) use ($vendingMachine, $assignmentIds, $geofenceIds, $deviceIds, $provisioningTokenIds): void {
                $query->where(fn ($machine) => $machine
                    ->where('auditable_type', $vendingMachine->getMorphClass())
                    ->where('auditable_id', $vendingMachine->getKey()))
                    ->orWhere(fn ($assignments) => $assignments
                        ->where('auditable_type', (new EmployeeMachineAssignment)->getMorphClass())
                        ->whereIn('auditable_id', $assignmentIds))
                    ->orWhere(fn ($geofences) => $geofences
                        ->where('auditable_type', (new MachineGeofence)->getMorphClass())
                        ->whereIn('auditable_id', $geofenceIds))
                    ->orWhere(fn ($devices) => $devices
                        ->where('auditable_type', (new Device)->getMorphClass())
                        ->whereIn('auditable_id', $deviceIds))
                    ->orWhere(fn ($tokens) => $tokens
                        ->where('auditable_type', (new DeviceProvisioningToken)->getMorphClass())
                        ->whereIn('auditable_id', $provisioningTokenIds));
            })
            ->latest('id')
            ->limit(25)
            ->get();

        $vendingMachine->devices->each(function (Device $device) use ($manifestStatuses): void {
            $device->setAttribute('manifest_sync', $manifestStatuses->status($device));
        });

        return Inertia::render('VendingMachines/Show', [
            'machine' => $vendingMachine,
            'auditLogs' => $auditLogs,
            'employees' => Employee::query()->orderBy('full_name')->limit(2000)->get(['id', 'fortia_employee_id', 'full_name', 'name', 'last_name']),
            'statuses' => VendingMachineStatus::values(),
            'coordinateSources' => CoordinateSource::values(),
            'assignmentTypes' => AssignmentType::values(),
            'geofenceStatuses' => [GeofenceStatus::DRAFT->value, GeofenceStatus::ACTIVE->value],
            'deviceStatuses' => DeviceStatus::values(),
        ]);
    }

    public function update(UpdateVendingMachineRequest $request, VendingMachine $vendingMachine): RedirectResponse
    {
        $vendingMachine->update($request->validated());

        return back()->with('success', 'Máquina vending actualizada.');
    }
}
