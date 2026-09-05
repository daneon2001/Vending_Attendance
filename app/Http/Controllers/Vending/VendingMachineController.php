<?php

namespace App\Http\Controllers\Vending;

use App\Enums\DeviceStatus;
use App\Enums\Vending\AssignmentType;
use App\Enums\Vending\CoordinateSource;
use App\Enums\Vending\GeofenceStatus;
use App\Enums\Vending\SybiVendingSourceStatus;
use App\Enums\Vending\SybiVendingSyncStatus;
use App\Enums\Vending\SybiVendingValidationStatus;
use App\Enums\Vending\VendingCatalogSource;
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
use App\Models\SybiVendingSourceRecord;
use App\Models\SybiVendingSyncRun;
use App\Models\VendingMachine;
use App\Services\Vending\DeviceManifestStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VendingMachineController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only([
            'catalog_view', 'search', 'status', 'source', 'sync_status',
            'source_status', 'validation_status', 'municipality', 'locality',
        ]);
        $filters['catalog_view'] = in_array($filters['catalog_view'] ?? null, ['operational', 'sybi'], true)
            ? $filters['catalog_view']
            : 'operational';
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
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($filters['sync_status'] ?? null, fn ($query, $status) => $query->where('sybi_sync_status', $status))
            ->when($filters['municipality'] ?? null, fn ($query, $value) => $query->where('municipality', $value))
            ->when($filters['locality'] ?? null, fn ($query, $value) => $query->where('locality', $value))
            ->orderBy('machine_code')
            ->paginate(25)
            ->withQueryString();

        $sourceRecords = SybiVendingSourceRecord::query()
            ->with('promotedVendingMachine:id,uuid,machine_code,status')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($nested) use ($search): void {
                $nested->where('sybi_id', 'like', "%{$search}%")
                    ->orWhere('identificador_vending', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->when($filters['source_status'] ?? null, fn ($query, $status) => $query->where('source_status', $status))
            ->when($filters['validation_status'] ?? null, fn ($query, $status) => $query->where('validation_status', $status))
            ->orderBy('sybi_id')
            ->paginate(25, ['*'], 'source_page')
            ->withQueryString();

        return Inertia::render('VendingMachines/Index', [
            'machines' => $machines,
            'sourceRecords' => $sourceRecords,
            'filters' => $filters,
            'statuses' => VendingMachineStatus::values(),
            'coordinateSources' => CoordinateSource::values(),
            'catalogSources' => VendingCatalogSource::values(),
            'syncStatuses' => SybiVendingSyncStatus::values(),
            'sourceStatuses' => SybiVendingSourceStatus::values(),
            'validationStatuses' => SybiVendingValidationStatus::values(),
            'municipalities' => VendingMachine::query()->whereNotNull('municipality')->distinct()->orderBy('municipality')->pluck('municipality'),
            'localities' => VendingMachine::query()->whereNotNull('locality')->distinct()->orderBy('locality')->pluck('locality'),
            'sybiIntegration' => [
                'configured' => filled(config('sybi.vending.url')) && filled(config('sybi.vending.token')),
                'automatic_sync_enabled' => (bool) config('sybi.vending.sync_enabled', false),
                'manual_creation_allowed' => $this->manualCreationAllowed(),
                'latest_run' => $this->latestSyncRun(),
                'result' => $request->session()->get('sybi_sync_result'),
            ],
        ]);
    }

    public function store(StoreVendingMachineRequest $request): RedirectResponse
    {
        if (! $this->manualCreationAllowed()) {
            throw ValidationException::withMessages([
                'machine_code' => 'La creación manual está deshabilitada porque SYBIML es el catálogo autoritativo.',
            ]);
        }

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
            $device->setAttribute('manifest_sync', $manifestStatuses->summary($device));
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
        $attributes = $request->validated();
        if ($vendingMachine->source === VendingCatalogSource::SYBI) {
            $this->assertSybiOwnedFieldsUnchanged($vendingMachine, $attributes);
            $attributes = collect($attributes)->except([
                'sybi_id', 'machine_code', 'name', 'address_line', 'neighborhood',
                'postal_code', 'latitude', 'longitude', 'coordinate_source',
            ])->all();
        }

        $vendingMachine->update($attributes);

        return back()->with('success', 'Máquina vending actualizada.');
    }

    private function manualCreationAllowed(): bool
    {
        return app()->environment(['local', 'testing'])
            || ! (bool) config('sybi.vending.catalog_authoritative', true);
    }

    private function latestSyncRun(): ?array
    {
        $run = SybiVendingSyncRun::query()->latest('started_at')->first();
        if (! $run) {
            return null;
        }

        return [
            'uuid' => $run->uuid,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
            'status' => $run->status->value,
            'received' => $run->received,
            'source_candidates' => $run->source_candidates,
            'source_created' => $run->source_created,
            'source_updated' => $run->source_updated,
            'source_unchanged' => $run->source_unchanged,
            'source_invalid' => $run->source_invalid,
            'operational_ready' => $run->operational_ready,
            'operational_created' => $run->operational_created,
            'operational_updated' => $run->operational_updated,
            'operational_unchanged' => $run->operational_unchanged,
            'operational_incomplete' => $run->operational_incomplete,
            'operational_conflicts' => $run->operational_conflicts,
            'operational_invalid' => $run->operational_invalid,
            'operational_missing' => $run->operational_missing,
            'created' => $run->created,
            'updated' => $run->updated,
            'unchanged' => $run->unchanged,
            'rejected' => $run->rejected,
            'conflicts' => $run->conflicts,
            'missing' => $run->missing,
            'duration_ms' => $run->duration_ms,
            'http_status' => $run->http_status,
            'error_code' => $run->error_code?->value,
            'warnings' => $run->warnings ?? [],
        ];
    }

    private function assertSybiOwnedFieldsUnchanged(VendingMachine $machine, array $attributes): void
    {
        $locked = [
            'sybi_id', 'machine_code', 'name', 'address_line', 'neighborhood',
            'postal_code', 'latitude', 'longitude', 'coordinate_source',
        ];
        $errors = [];

        foreach ($locked as $attribute) {
            if (! array_key_exists($attribute, $attributes)) {
                continue;
            }

            $current = $machine->getAttribute($attribute);
            $current = $current instanceof \BackedEnum ? $current->value : $current;
            $incoming = $attributes[$attribute];
            $same = in_array($attribute, ['latitude', 'longitude'], true)
                ? ($current === null && $incoming === null)
                    || ($current !== null && $incoming !== null && abs((float) $current - (float) $incoming) <= 0.00000005)
                : (string) ($current ?? '') === (string) ($incoming ?? '');

            if (! $same) {
                $errors[$attribute] = 'Este campo es propiedad de SYBIML y es de sólo lectura.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
