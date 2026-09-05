<?php

namespace App\Services\Vending;

use App\Enums\DeviceStatus;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\Device;
use App\Models\EmployeeMachineAssignment;
use App\Models\SybiVendingSyncRun;
use App\Models\VendingAttendanceEvent;
use App\Models\VendingMachine;
use Illuminate\Support\Collection;

class VendingFleetOperationsService
{
    public function __construct(
        private readonly DeviceFleetHealthService $health,
        private readonly MobileVersionPolicyService $versions,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $now = now();
        $machines = VendingMachine::query()->with('activeGeofence')->get();
        $devices = Device::query()
            ->whereNotNull('vending_machine_id')
            ->with(['vendingMachine', 'manifestStates', 'attendanceMetric'])
            ->get();
        $policies = $this->versions->policyMap();
        $deviceRows = $devices->map(fn (Device $device): array => [
            'device' => $device,
            'health' => $this->health->evaluate($device, $now, $this->versions->policyFor($device, $policies), true),
        ]);
        $healthCounts = $deviceRows->countBy('health.status')->all();
        $configurationSynced = $deviceRows->where('health.manifest.configuration.changed', false)->count();
        $employeesSynced = $deviceRows->where('health.manifest.employees.changed', false)->count();
        $readyGeofences = $machines->filter(fn (VendingMachine $machine): bool => $machine->hasVerifiedCoordinates()
            && ! $machine->geofence_review_required
            && $machine->activeGeofence !== null
        )->count();

        $latestSybi = SybiVendingSyncRun::query()->latest('started_at')->first();
        $todayUtc = now(config('app.timezone'))->startOfDay()->utc();
        $activeAssignments = EmployeeMachineAssignment::query()
            ->active()->effectiveAt($now)->withRelevantPermission()
            ->distinct('employee_id')->count('employee_id');

        return [
            'generated_at' => $now->utc()->toIso8601String(),
            'kpis' => [
                'machines_total' => $machines->count(),
                'machines_operational' => $machines->filter(fn (VendingMachine $machine): bool => in_array($machine->status, [
                    VendingMachineStatus::ACTIVE,
                    VendingMachineStatus::MAINTENANCE,
                ], true))->count(),
                'devices_active' => $devices->filter(fn (Device $device): bool => $device->status === DeviceStatus::ACTIVE)->count(),
                'devices_online' => $healthCounts['ONLINE'] ?? 0,
                'devices_degraded' => $healthCounts['DEGRADED'] ?? 0,
                'devices_offline' => $healthCounts['OFFLINE'] ?? 0,
                'geofences_ready' => $readyGeofences,
                'geofences_review' => $machines->count() - $readyGeofences,
                'employees_assigned' => $activeAssignments,
                'configuration_synced' => $configurationSynced,
                'configuration_pending' => $devices->count() - $configurationSynced,
                'employees_synced' => $employeesSynced,
                'employees_pending' => $devices->count() - $employeesSynced,
                'attendance_today' => VendingAttendanceEvent::query()->where('received_at_server', '>=', $todayUtc)->count(),
                'pending_edge_events' => (int) $devices->sum('pending_events_count'),
                'rejected_events_total' => (int) $devices->sum(fn (Device $device): int => (int) ($device->attendanceMetric?->rejected_total ?? 0)),
            ],
            'app_versions' => $devices
                ->groupBy(fn (Device $device): string => strtoupper((string) $device->platform).'|'.($device->app_version ?: 'UNKNOWN'))
                ->map(function (Collection $items, string $key): array {
                    [$platform, $version] = explode('|', $key, 2);

                    return ['platform' => $platform, 'version' => $version, 'devices' => $items->count()];
                })->values(),
            'alerts' => $this->alerts($deviceRows, $machines),
            'last_sybi_sync' => $latestSybi ? [
                'status' => $latestSybi->status->value,
                'started_at' => $latestSybi->started_at,
                'finished_at' => $latestSybi->finished_at,
            ] : null,
            'thresholds' => config('vending.device.health'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function alerts(Collection $deviceRows, Collection $machines): array
    {
        $alerts = [];
        foreach ($deviceRows as $row) {
            $device = $row['device'];
            $health = $row['health'];
            if (! in_array($health['status'], ['ONLINE', 'PENDING'], true)) {
                $alerts[] = [
                    'severity' => in_array($health['status'], ['OFFLINE', 'REVOKED'], true) ? 'HIGH' : 'MEDIUM',
                    'type' => 'DEVICE_'.$health['status'],
                    'machine_code' => $device->vendingMachine?->machine_code,
                    'device_uuid' => $device->uuid,
                    'message' => implode(', ', $health['reasons']),
                ];
            }
        }
        foreach ($machines->where('geofence_review_required', true) as $machine) {
            $alerts[] = [
                'severity' => 'MEDIUM',
                'type' => 'GEOFENCE_REVIEW',
                'machine_code' => $machine->machine_code,
                'device_uuid' => null,
                'message' => 'La geocerca requiere revisión operacional.',
            ];
        }

        return collect($alerts)
            ->sortBy(fn (array $alert): int => $alert['severity'] === 'HIGH' ? 0 : 1)
            ->take((int) config('vending.fleet.alerts_limit', 100))
            ->values()->all();
    }
}
