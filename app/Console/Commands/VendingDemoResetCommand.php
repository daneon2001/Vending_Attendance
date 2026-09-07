<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Enums\Employees\EmployeeSource;
use App\Enums\Vending\VendingCatalogSource;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;
use App\Services\Vending\DeviceFleetHealthService;
use App\Services\Vending\EmployeeManifestService;
use App\Services\Vending\MachineAssignmentService;
use App\Services\Vending\MachineConfigurationManifestService;
use Database\Seeders\VendingDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class VendingDemoResetCommand extends Command
{
    protected $signature = 'vending:demo-reset';

    protected $description = 'Restore the existing local DEMO setup without deleting evidence or simulating Android telemetry';

    private const MACHINE = 'VM-DEMO-001';

    private const DEVICE = 'ANDROID-DEMO-001';

    private const NAMES = [
        'DEMO1001' => ['Empleado Demo', 'Uno'],
        'DEMO1002' => ['Empleado Demo', 'Dos'],
        'DEMO1003' => ['Empleado Demo', 'Tres'],
        'DEMO1004' => ['Supervisor', 'Demo'],
        'DEMO1005' => ['Técnico', 'Demo'],
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])
            || ! in_array(config('app.env'), ['local', 'testing'], true)
            || ! in_array(Env::get('APP_ENV'), ['local', 'testing'], true)) {
            $this->error('Reset permitido únicamente en local/testing.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(fn (): array => $this->resetDataset(), 3);
        } catch (Throwable) {
            // Do not expose SQL bindings, credentials or exception payloads in console/logs.
            $this->error('Reset bloqueado; no se aplicaron cambios. Verifica las precondiciones de docs/demo/demo-checklist.md.');

            return self::FAILURE;
        }

        foreach ($result as $label => $value) {
            $this->line($label.': '.$value);
        }

        return self::SUCCESS;
    }

    private function resetDataset(): array
    {
        $machine = VendingMachine::query()->where('machine_code', self::MACHINE)->lockForUpdate()->first();
        $this->require($machine !== null && $this->ownsMachine($machine));

        // This command is not a provisioner. Never revive the retired synthetic terminal.
        $device = Device::query()->where('device_serial', self::DEVICE)->lockForUpdate()->first();
        $this->require($device !== null
            && (int) $device->vending_machine_id === (int) $machine->id
            && $device->status === DeviceStatus::ACTIVE && $device->is_active
            && $device->credential_revoked_at === null && filled($device->credential_secret));
        $this->require((int) ($device->pending_events_count ?? 0) === 0);
        $this->require($machine->devices()->where('status', DeviceStatus::ACTIVE)->count() === 1);

        // Existing approved geofence only: never move coordinates or invent a physical location.
        $geofence = $machine->geofences()->effectiveAt()->lockForUpdate()->first();
        $this->require($geofence !== null && $geofence->source === 'DEMO'
            && $machine->hasVerifiedCoordinates() && ! $machine->geofence_review_required
            && $geofence->center_latitude !== null && $geofence->center_longitude !== null
            && abs((float) $geofence->center_latitude) <= 90
            && abs((float) $geofence->center_longitude) <= 180
            && ! ((float) $geofence->center_latitude === 0.0 && (float) $geofence->center_longitude === 0.0)
            && $geofence->radius_m > 0);
        $this->require($machine->retired_at === null);

        $employees = [];
        foreach (self::NAMES as $alias => [$name, $lastName]) {
            $employees[$alias] = $this->employee($alias, $name, $lastName);
        }

        foreach (['VM1_PRIMARY' => 'DEMO1001', 'VM1_SUPERVISOR' => 'DEMO1004'] as $key => $alias) {
            $assignment = EmployeeMachineAssignment::query()
                ->where('uuid', VendingDemoSeeder::ASSIGNMENT_UUIDS[$key])->lockForUpdate()->first();
            if ($assignment) {
                // Revocations/history require explicit operator review, not implicit resurrection.
                $this->require((int) $assignment->vending_machine_id === (int) $machine->id
                    && (int) $assignment->employee_id === (int) $employees[$alias]->id
                    && $assignment->source === 'DEMO' && $assignment->revoked_at === null
                    && $assignment->status->value === 'ACTIVE'
                    && $assignment->attendance_allowed
                    && $assignment->valid_from->lte(now()) && $assignment->valid_until === null);

                continue;
            }

            $this->require(! $machine->assignments()->active()->where('employee_id', $employees[$alias]->id)->exists());
            app(MachineAssignmentService::class)->create($machine, [
                'uuid' => VendingDemoSeeder::ASSIGNMENT_UUIDS[$key],
                'employee_id' => $employees[$alias]->id,
                'assignment_type' => $key === 'VM1_PRIMARY' ? 'PRIMARY' : 'SUPERVISOR',
                'valid_from' => now()->utc(),
                'valid_until' => null,
                'attendance_allowed' => true,
                'enrollment_allowed' => false,
                'maintenance_allowed' => false,
                'source' => 'DEMO',
                'metadata' => ['demo' => true, 'alias' => $alias],
            ]);
        }

        $effective = $machine->assignments()->active()->effectiveAt()->withRelevantPermission()->get();
        $employeeIds = array_map(fn (Employee $employee): int => (int) $employee->id, $employees);
        $this->require($effective->count() >= 2 && $effective->count() <= 3
            && $effective->every(fn ($assignment): bool => $assignment->source === 'DEMO'
                && in_array((int) $assignment->employee_id, $employeeIds, true)));

        $machine->fill(['source' => VendingCatalogSource::DEMO, 'status' => VendingMachineStatus::ACTIVE]);
        if ($machine->isDirty()) {
            $machine->save();
        }

        // Use existing version/hash generation. These snapshots are NOT device acknowledgements.
        $configuration = app(MachineConfigurationManifestService::class)->snapshot($device);
        $manifest = app(EmployeeManifestService::class)->snapshot($machine->fresh());
        $health = app(DeviceFleetHealthService::class)->evaluate($device->fresh());

        return [
            'DEMO RESET' => 'PASS',
            'MACHINE' => self::MACHINE,
            'DEVICE' => self::DEVICE.' / ACTIVE / '.$health['status'],
            'EMPLOYEES' => count($employees).' / ASSIGNED '.$effective->count(),
            'MANIFESTS READY' => 'CONFIGURATION v'.$configuration['manifest_version'].' / EMPLOYEES v'.$manifest['manifest_version'],
            'MANIFEST SYNC' => $health['manifest']['sync_state'],
            'PENDING LAST REPORTED' => $device->pending_events_count === null ? 'UNKNOWN' : (string) $device->pending_events_count,
            'HISTORY' => 'PRESERVED',
            'PHYSICAL PREFLIGHT' => 'REQUIRED: ONLINE, SYNCED, local outbox 0, HIGH alerts 0',
            'DEMO' => 'NOT_READY_UNTIL_PHYSICAL_VALIDATION',
        ];
    }

    private function employee(string $alias, string $name, string $lastName): Employee
    {
        $legacyId = VendingDemoSeeder::EMPLOYEE_NUMBERS[$alias];
        $candidates = Employee::query()->where(function ($query) use ($alias, $legacyId): void {
            $query->where('employee_number', $alias)
                ->orWhere('employee_number', (string) $legacyId)
                ->orWhere('fortia_employee_id', $legacyId)
                ->orWhere(fn ($source) => $source->where('source', EmployeeSource::DEMO)
                    ->where('source_external_id', (string) $legacyId));
        })->lockForUpdate()->get();
        $this->require($candidates->count() <= 1);
        $employee = $candidates->first() ?? new Employee;
        if ($employee->exists) {
            // Legacy adoption needs the complete synthetic seeder signature, never just a prefix/number.
            $this->require(in_array($employee->source, [EmployeeSource::DEMO, EmployeeSource::LEGACY], true)
                && in_array($employee->source_external_id, [null, (string) $legacyId], true)
                && in_array($employee->employee_number, [$alias, (string) $legacyId], true)
                && ($employee->fortia_employee_id === null || (int) $employee->fortia_employee_id === $legacyId)
                && $employee->company_name === 'VENDING DEMO LOCAL'
                && $employee->full_name === $name.' '.$lastName);
            $this->require($employee->source !== EmployeeSource::LEGACY
                || (int) $employee->fortia_employee_id === $legacyId);
            foreach (['company_id', 'base_location_id', 'department_id', 'rfc', 'curp', 'imss_number', 'email_company'] as $field) {
                $this->require(blank($employee->$field));
            }
            $this->require(! $employee->has_fingerprint && ! $employee->has_face_enrollment
                && ! $employee->fingerprints()->exists());
            // An employee shared with non-DEMO operations is out of scope, including historical links.
            foreach (EmployeeMachineAssignment::query()->where('employee_id', $employee->id)->with('vendingMachine')->get() as $assignment) {
                $this->require($assignment->source === 'DEMO'
                    && $assignment->vendingMachine !== null && $this->ownsMachine($assignment->vendingMachine));
            }
        }

        $employee->fill([
            'employee_number' => $employee->employee_number ?? $alias,
            'source' => EmployeeSource::DEMO,
            'source_external_id' => (string) $legacyId,
            'name' => $name,
            'last_name' => $lastName,
            'full_name' => $name.' '.$lastName,
            'status' => 'A',
            'company_name' => 'VENDING DEMO LOCAL',
        ]);
        if (! $employee->exists || $employee->isDirty()) {
            $employee->save();
        }

        return $employee;
    }

    private function ownsMachine(VendingMachine $machine): bool
    {
        return ($machine->uuid === (VendingDemoSeeder::MACHINE_UUIDS[$machine->machine_code] ?? null))
            && in_array($machine->source, [VendingCatalogSource::LOCAL, VendingCatalogSource::DEMO], true)
            && $machine->sybi_id === null && ! $machine->sybiSourceRecord()->exists()
            && ($machine->metadata['demo'] ?? false) === true
            && ($machine->metadata['dataset'] ?? null) === 'VENDING_DEMO_V1';
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('DEMO prerequisite failed.');
        }
    }
}
