<?php

namespace App\Http\Controllers\Vending;

use App\Enums\DeviceStatus;
use App\Enums\Vending\FleetErrorCategory;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\VendingMachine;
use App\Services\Vending\DeviceFleetHealthService;
use App\Services\Vending\MobileVersionPolicyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendingDeviceRegistryController extends Controller
{
    public function __invoke(
        Request $request,
        DeviceFleetHealthService $health,
        MobileVersionPolicyService $versions,
    ): Response {
        $filters = $request->only([
            'search', 'machine', 'status', 'platform', 'app_version', 'release_channel',
            'sync_state', 'last_seen', 'clock_drift', 'pending_events', 'geofence',
        ]);
        $query = Device::query()
            ->whereNotNull('vending_machine_id')
            ->with(['vendingMachine.activeGeofence', 'manifestStates', 'attendanceMetric'])
            ->withCount(['vendingAttendanceEvents as attendance_last_24h' => fn (Builder $events) => $events
                ->where('received_at_server', '>=', now()->subDay())]);

        $query
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(function (Builder $nested) use ($search): void {
                $nested->where('uuid', 'like', "%{$search}%")
                    ->orWhere('device_serial', 'like', "%{$search}%")
                    ->orWhereHas('vendingMachine', fn (Builder $machine) => $machine->where('machine_code', 'like', "%{$search}%"));
            }))
            ->when($filters['machine'] ?? null, fn (Builder $q, string $machine) => $q->where('vending_machine_id', $machine))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['platform'] ?? null, fn (Builder $q, string $platform) => $q->where('platform', $platform))
            ->when($filters['app_version'] ?? null, fn (Builder $q, string $version) => $q->where('app_version', $version))
            ->when($filters['release_channel'] ?? null, fn (Builder $q, string $channel) => $q->where('release_channel', $channel));

        $this->applyOperationalFilters($query, $filters);
        $policies = $versions->policyMap();
        $devices = $query->orderByRaw('last_heartbeat_at IS NULL DESC')
            ->orderBy('last_heartbeat_at')
            ->paginate((int) config('vending.fleet.devices_per_page', 50))
            ->withQueryString()
            ->through(function (Device $device) use ($health, $versions, $policies): array {
                $snapshot = $health->evaluate($device, now(), $versions->policyFor($device, $policies), true);

                return [
                    'uuid' => $device->uuid,
                    'device_serial' => $device->device_serial,
                    'platform' => $device->platform,
                    'platform_version' => $device->platform_version,
                    'app_version' => $device->app_version,
                    'app_build_number' => $device->app_build_number,
                    'release_channel' => $device->release_channel,
                    'release_group' => $device->release_group,
                    'status' => $device->status->value,
                    'last_seen_at' => $device->last_seen_at,
                    'last_heartbeat_at' => $device->last_heartbeat_at,
                    'clock_drift_seconds' => $device->clock_drift_seconds,
                    'pending_events_count' => $device->pending_events_count,
                    'storage_free_mb' => $device->storage_free_mb,
                    'network_state' => $device->network_state,
                    'last_error_category' => $device->last_error_category,
                    'last_error_code' => $device->last_error_code,
                    'last_error_at' => $device->last_error_at,
                    'machine' => $device->vendingMachine ? [
                        'uuid' => $device->vendingMachine->uuid,
                        'machine_code' => $device->vendingMachine->machine_code,
                        'geofence_ready' => $device->vendingMachine->hasVerifiedCoordinates()
                            && ! $device->vendingMachine->geofence_review_required
                            && $device->vendingMachine->activeGeofence !== null,
                    ] : null,
                    'attendance' => $device->attendanceMetric ? [
                        'last_received_at' => $device->attendanceMetric->last_attendance_received_at,
                        'received_last_24h' => (int) $device->attendance_last_24h,
                        'rejected_total' => $device->attendanceMetric->rejected_total,
                        'duplicate_total' => $device->attendanceMetric->duplicate_total,
                        'geofence_mismatch_total' => $device->attendanceMetric->geofence_mismatch_total,
                        'sync_delay_average_seconds' => $device->attendanceMetric->sync_delay_samples > 0
                            ? round($device->attendanceMetric->sync_delay_total_seconds / $device->attendanceMetric->sync_delay_samples, 2)
                            : null,
                        'sync_delay_max_seconds' => $device->attendanceMetric->sync_delay_max_seconds,
                    ] : null,
                    'fleet' => $snapshot,
                ];
            });

        return Inertia::render('VendingFleet/Devices', [
            'devices' => $devices,
            'filters' => $filters,
            'machines' => VendingMachine::query()->orderBy('machine_code')->get(['id', 'uuid', 'machine_code']),
            'statuses' => DeviceStatus::values(),
            'platforms' => Device::query()->whereNotNull('vending_machine_id')->whereNotNull('platform')->distinct()->orderBy('platform')->pluck('platform'),
            'appVersions' => Device::query()->whereNotNull('vending_machine_id')->whereNotNull('app_version')->distinct()->orderBy('app_version')->pluck('app_version'),
            'errorCategories' => FleetErrorCategory::values(),
            'thresholds' => config('vending.device.health'),
            'canManage' => $request->user()?->hasPermission('vending_machines', 'manage')
                || $request->user()?->hasPermission('settings', 'manage'),
        ]);
    }

    private function applyOperationalFilters(Builder $query, array $filters): void
    {
        $offlineAt = now()->subSeconds((int) config('vending.device.health.offline_after_seconds', 600));
        $degradedAt = now()->subSeconds((int) config('vending.device.health.degraded_after_seconds', 180));
        if (($filters['last_seen'] ?? null) === 'offline') {
            $query->where(fn (Builder $q) => $q->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<=', $offlineAt));
        } elseif (($filters['last_seen'] ?? null) === 'recent') {
            $query->where('last_heartbeat_at', '>', $degradedAt);
        } elseif (($filters['last_seen'] ?? null) === 'delayed') {
            $query->whereBetween('last_heartbeat_at', [$offlineAt, $degradedAt]);
        } elseif (($filters['last_seen'] ?? null) === 'never') {
            $query->whereNull('last_heartbeat_at');
        }

        if (($filters['clock_drift'] ?? null) === 'warning') {
            $limit = (int) config('vending.device.health.clock_drift_seconds', 300);
            $query->where(fn (Builder $q) => $q->where('clock_drift_seconds', '>', $limit)->orWhere('clock_drift_seconds', '<', -$limit));
        }
        if (($filters['pending_events'] ?? null) === 'any') {
            $query->where('pending_events_count', '>', 0);
        } elseif (($filters['pending_events'] ?? null) === 'high') {
            $query->where('pending_events_count', '>=', (int) config('vending.device.health.pending_events_count', 100));
        } elseif (($filters['pending_events'] ?? null) === 'none') {
            $query->where(fn (Builder $q) => $q->whereNull('pending_events_count')->orWhere('pending_events_count', 0));
        }
        if (($filters['geofence'] ?? null) === 'READY') {
            $query->whereHas('vendingMachine', fn (Builder $machine) => $machine
                ->where('coordinates_verified', true)->where('geofence_review_required', false)->whereHas('activeGeofence'));
        } elseif (($filters['geofence'] ?? null) === 'REVIEW') {
            $query->whereHas('vendingMachine', fn (Builder $machine) => $machine
                ->where(fn (Builder $q) => $q->where('coordinates_verified', false)->orWhere('geofence_review_required', true)->orWhereDoesntHave('activeGeofence')));
        }

        $sync = $filters['sync_state'] ?? null;
        if ($sync === 'ERROR') {
            $query->whereHas('manifestStates', fn (Builder $state) => $state->where('last_ack_status', 'FAILED'));
        } elseif ($sync === 'SYNCED') {
            $query->whereDoesntHave('manifestStates', fn (Builder $state) => $state->where('last_ack_status', 'FAILED'))
                ->whereHas('vendingMachine', fn (Builder $machine) => $machine
                    ->whereColumn('vending_machines.config_version', 'devices.config_version_applied')
                    ->whereColumn('vending_machines.employee_manifest_version', 'devices.employee_manifest_version_applied'));
        } elseif ($sync === 'PENDING') {
            $staleAt = now()->subMinutes((int) config('vending.manifests.stale_after_minutes', 10));
            $lag = (int) config('vending.manifests.stale_version_lag', 3);
            $query->whereDoesntHave('manifestStates', fn (Builder $state) => $state->where('last_ack_status', 'FAILED'))
                ->where(fn (Builder $seen) => $seen->whereNull('last_seen_at')->orWhere('last_seen_at', '>', $staleAt))
                ->whereHas('vendingMachine', fn (Builder $machine) => $machine
                    ->where(function (Builder $changed): void {
                        $changed->whereNull('devices.config_version_applied')
                            ->orWhereNull('devices.employee_manifest_version_applied')
                            ->orWhereColumn('vending_machines.config_version', '<>', 'devices.config_version_applied')
                            ->orWhereColumn('vending_machines.employee_manifest_version', '<>', 'devices.employee_manifest_version_applied');
                    })
                    ->where(fn (Builder $config) => $config
                        ->whereNull('devices.config_version_applied')
                        ->orWhereRaw('(vending_machines.config_version - devices.config_version_applied) < ?', [$lag]))
                    ->where(fn (Builder $employees) => $employees
                        ->whereNull('devices.employee_manifest_version_applied')
                        ->orWhereRaw('(vending_machines.employee_manifest_version - devices.employee_manifest_version_applied) < ?', [$lag])));
        } elseif ($sync === 'STALE') {
            $staleAt = now()->subMinutes((int) config('vending.manifests.stale_after_minutes', 10));
            $lag = (int) config('vending.manifests.stale_version_lag', 3);
            $query->whereDoesntHave('manifestStates', fn (Builder $state) => $state->where('last_ack_status', 'FAILED'))
                ->where(function (Builder $stale) use ($staleAt, $lag): void {
                    $stale->where(fn (Builder $seen) => $seen->whereNotNull('last_seen_at')->where('last_seen_at', '<=', $staleAt))
                        ->orWhereHas('vendingMachine', fn (Builder $machine) => $machine->where(function (Builder $versions) use ($lag): void {
                            $versions->where(fn (Builder $config) => $config
                                ->whereNotNull('devices.config_version_applied')
                                ->whereRaw('(vending_machines.config_version - devices.config_version_applied) >= ?', [$lag]))
                                ->orWhere(fn (Builder $employees) => $employees
                                    ->whereNotNull('devices.employee_manifest_version_applied')
                                    ->whereRaw('(vending_machines.employee_manifest_version - devices.employee_manifest_version_applied) >= ?', [$lag]));
                        }));
                });
        }
    }
}
