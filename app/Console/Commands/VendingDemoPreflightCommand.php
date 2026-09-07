<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Enums\Employees\EmployeeSource;
use App\Enums\Vending\GeofenceShape;
use App\Enums\Vending\VendingCatalogSource;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use App\Services\Vending\DeviceFleetHealthService;
use App\Services\Vending\VendingFleetOperationsService;
use Database\Seeders\VendingDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Throwable;

class VendingDemoPreflightCommand extends Command
{
    protected $signature = 'vending:demo-preflight';

    protected $description = 'Read-only readiness checks for the existing local vending demo, not a production certification';

    private const MACHINE = 'VM-DEMO-001';

    private const DEVICE = 'ANDROID-DEMO-001';

    public function handle(): int
    {
        $local = app()->environment(['local', 'testing'])
            && in_array(config('app.env'), ['local', 'testing'], true)
            && in_array(Env::get('APP_ENV'), ['local', 'testing'], true);
        $checks = [['LOCAL DEMO ENVIRONMENT', $local, $local ? 'local/testing' : 'Fuera de alcance']];

        if ($local) {
            try {
                $checks = array_merge($checks, $this->checks());
            } catch (Throwable) {
                // Never report/log exceptions: SQL bindings and telemetry may contain secrets.
                $checks[] = ['DATA AVAILABLE', false, 'No fue posible verificar los datos'];
            }
        }

        $this->line('Sólo lectura de evidencia persistida; no realiza una prueba activa de red/GPS ni certifica producción.');
        $this->table(
            ['Check', 'Result', 'Value'],
            array_map(fn (array $check): array => [$check[0], $check[1] ? 'PASS' : 'FAIL', $check[2]], $checks),
        );
        $ready = ! collect($checks)->contains(fn (array $check): bool => ! $check[1]);
        $this->line('DEMO PREFLIGHT: '.($ready ? 'PASS' : 'FAIL'));
        $this->line($ready ? 'READY_FOR_LIVE_DEMO' : 'NOT_READY_FOR_LIVE_DEMO');

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    private function checks(): array
    {
        $now = now();
        $machine = VendingMachine::query()->where('machine_code', self::MACHINE)->first();
        $device = Device::query()->where('device_serial', self::DEVICE)
            ->with(['vendingMachine', 'manifestStates'])->first();
        $linked = $machine !== null && $device !== null
            && (int) $device->vending_machine_id === (int) $machine->id;

        // evaluate() uses the read-only manifest summary. Never call status(),
        // snapshot(), demo-reset or a seeder: they may materialize persisted state.
        $health = $linked ? app(DeviceFleetHealthService::class)->evaluate($device, $now) : null;
        $age = $health['heartbeat_age_seconds'] ?? null;
        $freshBefore = min(
            (int) config('vending.device.health.degraded_after_seconds'),
            (int) config('vending.device.health.offline_after_seconds'),
        );
        $recent = $age !== null && $age >= 0 && $age < $freshBefore
            && $device->last_heartbeat_at->lte($now);
        $network = in_array($device?->network_state, ['ONLINE', 'OFFLINE', 'UNKNOWN'], true)
            ? $device->network_state : 'UNKNOWN';
        $geofence = $machine?->geofences()->effectiveAt($now)->first();
        $geofenceReady = $this->geofenceReady($machine, $geofence);
        $employees = Employee::query()->where('source', EmployeeSource::DEMO)
            ->activeForVending()
            ->whereIn('source_external_id', array_map('strval', VendingDemoSeeder::EMPLOYEE_NUMBERS))
            ->get(['id', 'employee_number', 'source_external_id'])
            ->groupBy('source_external_id');
        $expected = [];
        foreach (VendingDemoSeeder::EMPLOYEE_NUMBERS as $alias => $number) {
            $candidates = $employees->get((string) $number, collect());
            if ($candidates->count() === 1
                && in_array($candidates->first()->employee_number, [$alias, (string) $number], true)) {
                $expected[$alias] = $candidates->first();
            }
        }

        $assignments = 0;
        foreach (['VM1_PRIMARY' => 'DEMO1001', 'VM1_SUPERVISOR' => 'DEMO1004'] as $key => $alias) {
            if ($machine !== null && isset($expected[$alias])
                && EmployeeMachineAssignment::query()
                    ->where('uuid', VendingDemoSeeder::ASSIGNMENT_UUIDS[$key])
                    ->where('vending_machine_id', $machine->id)
                    ->where('employee_id', $expected[$alias]->id)
                    ->where('assignment_type', $key === 'VM1_PRIMARY' ? 'PRIMARY' : 'SUPERVISOR')
                    ->where('source', 'DEMO')->active()->effectiveAt($now)->attendanceAllowed()->exists()) {
                $assignments++;
            }
        }

        // Reuse the exact dashboard alert projection across the local fleet.
        // HIGH sorts before MEDIUM, so a zero HIGH count cannot hide a HIGH behind the limit.
        $alerts = collect(app(VendingFleetOperationsService::class)->dashboard()['alerts']);
        $high = $alerts->where('severity', 'HIGH')->count();
        $medium = $alerts->where('severity', 'MEDIUM')->count();

        return [
            ['MACHINE EXISTS', $machine !== null, self::MACHINE],
            ['MACHINE DEMO', $machine !== null
                && $machine->source === VendingCatalogSource::DEMO
                && $machine->uuid === VendingDemoSeeder::MACHINE_UUIDS[self::MACHINE], 'Identidad DEMO esperada'],
            // The live demo includes attendance; MachineAuthorizationService requires ACTIVE,
            // even though the fleet KPI also counts MAINTENANCE as operational.
            ['MACHINE OPERATIONAL', $machine?->status === VendingMachineStatus::ACTIVE
                && $machine->retired_at === null, $machine?->status?->value ?? 'MISSING'],
            ['DEVICE EXISTS', $device !== null, self::DEVICE],
            ['DEVICE MACHINE', $linked, self::MACHINE],
            ['DEVICE ACTIVE', $device?->status === DeviceStatus::ACTIVE && $device->is_active,
                $device?->status?->value ?? 'MISSING'],
            ['HEARTBEAT RECENT', $recent, ($age === null ? 'UNKNOWN' : $age.' s').' / < '.$freshBefore.' s'],
            ['NETWORK LAST REPORTED', $network === 'ONLINE', $network],
            $this->manifestCheck('CONFIGURATION MANIFEST', $health['manifest']['configuration'] ?? null),
            $this->manifestCheck('EMPLOYEE MANIFEST', $health['manifest']['employees'] ?? null),
            ['OUTBOX LAST REPORTED', $device?->pending_events_count === 0,
                $device?->pending_events_count === null ? 'UNKNOWN' : (string) $device->pending_events_count],
            ['GEOFENCE DEMO READY', $geofenceReady, $geofenceReady
                ? 'Activa y vigente; coordenadas omitidas' : 'Faltante, no vigente o requiere revisión'],
            ['DEMO EMPLOYEES', count($expected) === count(VendingDemoSeeder::EMPLOYEE_NUMBERS),
                count($expected).' / '.count(VendingDemoSeeder::EMPLOYEE_NUMBERS).' activos esperados'],
            ['DEMO ASSIGNMENTS', $assignments === 2, $assignments.' / 2 esperados vigentes'],
            ['HIGH ALERTS', $high === 0, (string) $high.' en proyección del dashboard'],
            ['MEDIUM ALERTS', true, $medium.' informativas; no bloquean por sí solas'],
        ];
    }

    private function manifestCheck(string $label, ?array $manifest): array
    {
        return [
            $label,
            ($manifest['state'] ?? null) === 'SYNCED' && ($manifest['changed'] ?? true) === false,
            ($manifest['state'] ?? 'UNKNOWN').' / server '.($manifest['server_version'] ?? '?')
                .' / applied '.($manifest['applied_version'] ?? '?'),
        ];
    }

    private function geofenceReady(?VendingMachine $machine, ?MachineGeofence $geofence): bool
    {
        return $machine !== null && $machine->hasVerifiedCoordinates() && ! $machine->geofence_review_required
            && $geofence !== null && $geofence->source === 'DEMO' && $geofence->shape === GeofenceShape::CIRCLE
            && $geofence->center_latitude !== null && $geofence->center_longitude !== null
            && abs((float) $geofence->center_latitude) <= 90 && abs((float) $geofence->center_longitude) <= 180
            && ! ((float) $geofence->center_latitude === 0.0 && (float) $geofence->center_longitude === 0.0)
            && $geofence->radius_m > 0 && $geofence->tolerance_m >= 0
            && ($geofence->minimum_acceptable_accuracy_m === null || $geofence->minimum_acceptable_accuracy_m >= 0);
    }
}
