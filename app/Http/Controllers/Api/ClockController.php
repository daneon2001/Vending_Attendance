<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClockAssignmentRequest;
use App\Http\Requests\ClockRequest;
use App\Http\Resources\ClockResource;
use App\Models\Clock;
use App\Models\ClockLog;
use App\Models\Device;
use App\Services\OnPrem\DeviceRegistryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ClockController extends Controller
{
    public function __construct(
        private readonly DeviceRegistryService $deviceRegistry,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        // Optional filter for on-prem clients:
        // GET .../clock-catalog?serial_number=FT-CHK-001
        $query = Clock::with(['company', 'location'])
            ->orderBy('clock_name');

        if ($request->filled('serial_number')) {
            $query->where('serial_number', trim((string) $request->query('serial_number')));
        }

        $clocks = $query->get();

        return response()->json([
            'data' => ClockResource::collection($clocks)->resolve(),
        ]);
    }

    public function resolveBySerial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serial_number' => ['nullable', 'string', 'max:120'],
            'device_serial' => ['nullable', 'string', 'max:120'],
        ]);

        $serial = trim((string) ($validated['serial_number'] ?? $validated['device_serial'] ?? ''));
        if ($serial === '') {
            return response()->json([
                'message' => 'serial_number is required.',
            ], 422);
        }

        $matches = Clock::query()
            ->with(['company', 'location'])
            ->where('serial_number', $serial)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->isEmpty()) {
            return response()->json([
                'message' => 'Clock not found.',
            ], 404);
        }

        if ($matches->count() > 1) {
            return response()->json([
                'message' => 'Multiple clocks share the provided serial number.',
            ], 409);
        }

        $clock = $matches->first();
        $this->deviceRegistry->syncFromClock($clock, $serial);

        $device = null;
        if (Schema::hasTable('devices')) {
            $device = Device::query()
                ->where('device_serial', $serial)
                ->first();
        }

        $deviceToken = trim((string) config('device.static_token', ''));
        $sharedSecret = trim((string) ($device?->shared_secret ?: config('onprem.default_shared_secret', '')));
        $timezone = trim((string) ($clock->location?->timezone ?: config('app.timezone', 'UTC')));
        $warnings = [];

        if ((int) ($clock->status ?? 0) !== 1) {
            $warnings[] = 'El reloj esta inhabilitado.';
        }
        if (! $clock->location_id) {
            $warnings[] = 'El reloj no tiene unidad asignada.';
        }
        if ($deviceToken === '') {
            $warnings[] = 'DEVICE_STATIC_TOKEN no esta configurado.';
        }
        if ($sharedSecret === '') {
            $warnings[] = 'El shared secret onprem no esta configurado para este dispositivo.';
        }

        return response()->json([
            'success' => true,
            'message' => 'Clock configuration resolved.',
            'data' => [
                'clock_id' => (int) $clock->id,
                'clock_name' => (string) $clock->clock_name,
                'device_serial' => $serial,
                'clock_enabled' => (int) ($clock->status ?? 0) === 1,
                'monitoring_status' => (string) ($clock->monitoring_status ?? 'offline'),
                'last_heartbeat_at' => optional($clock->last_heartbeat_at)?->toIso8601String(),
                'unit_id' => $clock->location_id ? (int) $clock->location_id : null,
                'unit_display_name' => $clock->location?->name,
                'company_id' => $clock->company_id ? (int) $clock->company_id : null,
                'company_display_name' => $clock->company?->name,
                'timezone' => $timezone !== '' ? $timezone : 'UTC',
                'base_url' => rtrim($request->getSchemeAndHttpHost(), '/'),
                'device_token' => $deviceToken !== '' ? $deviceToken : null,
                'device_shared_secret' => $sharedSecret !== '' ? $sharedSecret : null,
                'device_display_name' => trim((string) ($clock->clock_name ?: ('Reloj '.$serial))),
                'device_registry' => [
                    'registered' => $device !== null,
                    'is_active' => $device?->is_active,
                    'last_seen_at' => optional($device?->last_seen_at)?->toIso8601String(),
                ],
                'intervals' => [
                    'employee_sync_minutes' => 15,
                    'template_sync_minutes' => 15,
                    'heartbeat_seconds' => max(5, (int) config('onprem.next_heartbeat_seconds', 15)),
                    'ping_minutes' => 5,
                    'check_retry_seconds' => 30,
                    'catalog_max_age_hours' => 24,
                ],
                'endpoints' => [
                    'clock_catalog' => url('/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog'),
                    'clock_heartbeat' => url('/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/'.$clock->id.'/heartbeat'),
                    'employees_catalog' => url('/api/FortiaPrimeApi.Opensync/api/v2/employees/catalog'),
                    'employee_templates' => url('/api/FortiaPrimeApi.Opensync/api/v2/employees/templates'),
                    'device_ping' => url('/api/device/ping'),
                    'onprem_ping' => url('/api/onprem/ping'),
                    'onprem_heartbeat' => url('/api/onprem/heartbeat'),
                    'onprem_attendances' => url('/api/onprem/attendances'),
                    'check_final' => url('/api/onprem/attendances'),
                ],
                'warnings' => $warnings,
            ],
        ]);
    }

    public function store(ClockRequest $request): JsonResponse
    {
        $clock = Clock::create($request->validated());
        $this->syncDeviceRegistry($clock, $request);

        return response()->json([
            'message' => 'Clock created',
            'data' => ClockResource::make($clock->load(['company', 'location']))->resolve(),
        ], 201);
    }

    public function update(ClockRequest $request, Clock $clock): JsonResponse
    {
        $clock->update($request->validated());
        $this->syncDeviceRegistry($clock, $request);

        return response()->json([
            'message' => 'Clock updated',
            'data' => ClockResource::make($clock->load(['company', 'location']))->resolve(),
        ]);
    }

    public function assign(ClockAssignmentRequest $request, Clock $clock): JsonResponse
    {
        $clock->update([
            'location_id' => $request->location_id,
        ]);
        $this->syncDeviceRegistry($clock, $request);

        return response()->json([
            'message' => 'Clock assigned to location',
            'data' => ClockResource::make($clock->load(['company', 'location']))->resolve(),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clock_id' => ['nullable', 'integer', 'exists:clocks,id'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'heartbeat_at' => ['nullable', 'date'],
            'device_timestamp' => ['nullable', 'date'],
            'monitoring_status' => ['nullable', 'in:online,offline,warning'],
            'program_status' => ['nullable', 'string', 'max:30'],
            'status_message' => ['nullable', 'string', 'max:255'],
            'last_status_message' => ['nullable', 'string', 'max:255'],
            'ip_local' => ['nullable', 'string', 'max:45'],
            'pending_attendance' => ['nullable', 'integer', 'min:0'],
            'pending_errors' => ['nullable', 'integer', 'min:0'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:100'],
            'payload' => ['nullable', 'array'],
        ]);

        if (! isset($validated['clock_id']) && ! isset($validated['serial_number'])) {
            return response()->json([
                'message' => 'clock_id or serial_number is required.',
            ], 422);
        }

        $clock = Clock::query()
            ->when(isset($validated['clock_id']), fn ($query) => $query->whereKey($validated['clock_id']))
            ->when(! isset($validated['clock_id']) && isset($validated['serial_number']), fn ($query) => $query->where('serial_number', $validated['serial_number']))
            ->first();

        if (! $clock) {
            return response()->json([
                'message' => 'Clock not found.',
            ], 404);
        }

        $this->applyHeartbeatUpdate($clock, $validated, $request, $validated['source'] ?? 'python-autosync');
        $this->syncDeviceRegistry($clock, $request);

        return response()->json([
            'message' => 'Heartbeat updated',
            'data' => ClockResource::make($clock->fresh(['company', 'location']))->resolve(),
        ]);
    }

    public function heartbeatByClock(Request $request, mixed $clock): JsonResponse
    {
        if (! $clock instanceof Clock) {
            $clock = Clock::query()->find($clock);
            if (! $clock) {
                return response()->json([
                    'message' => 'Clock not found.',
                ], 404);
            }
        }

        $validated = $request->validate([
            'monitoring_status' => ['nullable', 'in:online,offline,warning'],
            'last_status_message' => ['nullable', 'string', 'max:255'],
            'status_message' => ['nullable', 'string', 'max:255'],
            'program_status' => ['nullable', 'string', 'max:80'],
            'device_timestamp' => ['nullable', 'date'],
            'heartbeat_at' => ['nullable', 'date'],
            'pending_attendance' => ['nullable', 'integer', 'min:0'],
            'pending_errors' => ['nullable', 'integer', 'min:0'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'ip_local' => ['nullable', 'string', 'max:45'],
            'payload' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:100'],
        ]);

        $this->applyHeartbeatUpdate($clock, $validated, $request, $validated['source'] ?? 'checador-app');
        $this->syncDeviceRegistry($clock, $request);

        return response()->json([
            'success' => true,
            'clock_id' => (int) $clock->id,
            'server_time' => now()->toIso8601String(),
            'saved' => true,
        ]);
    }

    private function syncDeviceRegistry(Clock $clock, Request $request): void
    {
        $serial = $request->input('device_serial')
            ?? $request->input('serial')
            ?? $request->input('serial_number')
            ?? $clock->getAttribute('device_serial')
            ?? $clock->getAttribute('serial')
            ?? $clock->getAttribute('serial_number')
            ?? $clock->getAttribute('device_id')
            ?? $clock->getAttribute('st_Serial');

        $incomingSecret = trim((string) $request->input('onprem_shared_secret', ''));
        $this->deviceRegistry->syncFromClock(
            $clock,
            is_scalar($serial) ? (string) $serial : null,
            $incomingSecret !== '' ? $incomingSecret : null,
        );
    }

    /**
     * @return array{heartbeat_at:\Carbon\Carbon}
     */
    private function applyHeartbeatUpdate(Clock $clock, array $validated, Request $request, string $source): array
    {
        $heartbeatAt = isset($validated['device_timestamp'])
            ? Carbon::parse($validated['device_timestamp'])
            : (isset($validated['heartbeat_at']) ? Carbon::parse($validated['heartbeat_at']) : now());

        $programStatus = strtolower((string) ($validated['program_status'] ?? 'online'));
        $programStatus = match ($programStatus) {
            'on', 'running' => 'online',
            'off' => 'offline',
            default => $programStatus !== '' ? $programStatus : 'online',
        };

        $statusMessage = $validated['last_status_message']
            ?? $validated['status_message']
            ?? $clock->last_status_message;

        $ip = trim((string) ($validated['ip_local'] ?? ''));
        if ($ip === '') {
            $ip = (string) $request->ip();
        }

        $clock->update([
            'last_heartbeat_at' => $heartbeatAt,
            'monitoring_status' => $validated['monitoring_status'] ?? 'online',
            'program_status' => $programStatus,
            'last_status_message' => $statusMessage,
            'last_seen_ip' => $ip !== '' ? $ip : null,
        ]);

        $payload = $validated['payload'] ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        foreach (['pending_attendance', 'pending_errors', 'app_version', 'ip_local'] as $extraKey) {
            if (array_key_exists($extraKey, $validated)) {
                $payload[$extraKey] = $validated[$extraKey];
            }
        }

        ClockLog::create([
            'clock_id' => $clock->id,
            'event_type' => 'heartbeat',
            'level' => 'info',
            'source' => $source,
            'message' => 'Heartbeat recibido',
            'payload' => $payload ?: null,
            'occurred_at' => $heartbeatAt,
        ]);

        return [
            'heartbeat_at' => $heartbeatAt,
        ];
    }
}
