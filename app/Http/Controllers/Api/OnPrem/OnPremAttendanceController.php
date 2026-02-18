<?php

namespace App\Http\Controllers\Api\OnPrem;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnPrem\StoreOnPremAttendancesRequest;
use App\Models\AttendanceRaw;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class OnPremAttendanceController extends Controller
{
    public function store(StoreOnPremAttendancesRequest $request): JsonResponse
    {
        /** @var Device|null $device */
        $device = $request->attributes->get('onprem_device');

        if (! $device) {
            return response()->json([
                'message' => 'Device not resolved.',
            ], 401);
        }

        $validated = $request->validated();
        $payloadHash = (string) ($request->attributes->get('onprem_payload_hash') ?? hash('sha256', (string) $request->getContent()));

        if ((string) $validated['device_serial'] !== (string) $device->device_serial) {
            return response()->json([
                'message' => 'device_serial mismatch.',
            ], 422);
        }

        foreach (['clock_id', 'unit_id', 'company_id'] as $field) {
            $requestValue = $validated[$field] ?? null;
            $deviceValue = $device->{$field};

            if ($requestValue !== null && $deviceValue !== null && (int) $requestValue !== (int) $deviceValue) {
                return response()->json([
                    'message' => sprintf('%s mismatch for device.', $field),
                ], 422);
            }
        }

        $received = [];
        $rejected = [];

        foreach ($validated['punches'] as $punch) {
            $punchValidator = Validator::make($punch, [
                'local_event_id' => ['required', 'uuid'],
                'collaborator_id' => ['required', 'integer'],
                'event_time_utc' => ['required', 'date'],
                'event_time_local' => ['required', 'date'],
                'tz' => ['required', 'timezone'],
                'type_inout' => ['required', Rule::in(['IN', 'OUT', 'INOUT'])],
                'source' => ['required', 'string', 'max:80'],
                'meta' => ['nullable', 'array'],
            ]);

            $localEventId = (string) ($punch['local_event_id'] ?? '');

            if ($punchValidator->fails()) {
                $error = (string) $punchValidator->errors()->first();
                $rejected[] = [
                    'local_event_id' => $localEventId !== '' ? $localEventId : null,
                    'error' => $error,
                ];

                AuditLogger::log(
                    event: 'onprem.punch.rejected',
                    auditable: $device,
                    description: 'Punch rejected',
                    metadata: [
                        'device_serial' => $device->device_serial,
                        'local_event_id' => $localEventId !== '' ? $localEventId : null,
                        'payload_hash' => $payloadHash,
                        'ip' => $request->ip(),
                        'error' => $error,
                    ],
                );

                continue;
            }

            $punchData = $punchValidator->validated();
            $localEventId = (string) $punchData['local_event_id'];

            try {
                $eventTimeUtc = CarbonImmutable::parse($punchData['event_time_utc'])->utc();
                $tz = (string) ($punchData['tz'] ?? $validated['timezone'] ?? 'UTC');
                $eventTimeLocal = CarbonImmutable::parse($punchData['event_time_local'])->setTimezone($tz);

                try {
                    $record = AttendanceRaw::query()->firstOrCreate(
                        [
                            'device_serial' => $device->device_serial,
                            'local_event_id' => $localEventId,
                        ],
                        [
                            'device_id' => $device->id,
                            'collaborator_id' => (int) $punchData['collaborator_id'],
                            'clock_id' => isset($validated['clock_id']) ? (int) $validated['clock_id'] : $device->clock_id,
                            'unit_id' => isset($validated['unit_id']) ? (int) $validated['unit_id'] : $device->unit_id,
                            'company_id' => isset($validated['company_id']) ? (int) $validated['company_id'] : $device->company_id,
                            'event_time_utc' => $eventTimeUtc,
                            'event_time_local' => $eventTimeLocal,
                            'tz' => $tz,
                            'type' => strtoupper((string) $punchData['type_inout']),
                            'source' => (string) $punchData['source'],
                            'meta' => $punchData['meta'] ?? null,
                        ],
                    );
                } catch (QueryException $queryException) {
                    $record = AttendanceRaw::query()
                        ->where('device_serial', $device->device_serial)
                        ->where('local_event_id', $localEventId)
                        ->first();

                    if (! $record) {
                        throw $queryException;
                    }
                }

                $received[] = [
                    'local_event_id' => $localEventId,
                    'remote_event_id' => (int) $record->remote_event_id,
                    'stored' => true,
                ];

                AuditLogger::log(
                    event: 'onprem.punch.received',
                    auditable: $device,
                    description: $record->wasRecentlyCreated ? 'Punch stored' : 'Punch idempotent',
                    metadata: [
                        'device_serial' => $device->device_serial,
                        'local_event_id' => $localEventId,
                        'remote_event_id' => (int) $record->remote_event_id,
                        'payload_hash' => $payloadHash,
                        'ip' => $request->ip(),
                    ],
                );
            } catch (Throwable $exception) {
                $rejected[] = [
                    'local_event_id' => $localEventId,
                    'error' => $exception->getMessage(),
                ];

                AuditLogger::log(
                    event: 'onprem.punch.rejected',
                    auditable: $device,
                    description: 'Punch rejected',
                    metadata: [
                        'device_serial' => $device->device_serial,
                        'local_event_id' => $localEventId,
                        'payload_hash' => $payloadHash,
                        'ip' => $request->ip(),
                        'error' => $exception->getMessage(),
                    ],
                );
            }
        }

        return response()->json([
            'ok' => true,
            'received' => $received,
            'rejected' => $rejected,
        ]);
    }
}
