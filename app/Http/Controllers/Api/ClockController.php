<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClockAssignmentRequest;
use App\Http\Requests\ClockRequest;
use App\Http\Resources\ClockResource;
use App\Models\Clock;
use App\Models\ClockLog;
use App\Services\OnPrem\DeviceRegistryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        // En catalogos legacy puede existir reloj sin serie; no hay llave para mapear device.
        if (trim((string) $clock->serial_number) === '') {
            return;
        }

        $incomingSecret = trim((string) $request->input('onprem_shared_secret', ''));
        $this->deviceRegistry->syncFromClock(
            $clock,
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
