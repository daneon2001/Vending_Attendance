<?php

namespace App\Http\Controllers\Api\OnPrem;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnPrem\StoreOnPremAttendancesRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRaw;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Location;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
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
            return $this->errorResponse(401, 'DEVICE_NOT_ACTIVE', 'Device not resolved.');
        }

        $validated = $request->validated();
        $payloadHash = (string) ($request->attributes->get('onprem_payload_hash') ?? hash('sha256', (string) $request->getContent()));

        if ((string) $validated['device_serial'] !== (string) $device->device_serial) {
            return $this->errorResponse(422, 'VALIDATION_FAILED', 'device_serial mismatch.');
        }

        $results = [];
        $unitId = (int) $validated['unit_id'];
        $unit = Location::query()->find($unitId);

        if (! $unit) {
            foreach ($validated['events'] as $event) {
                $results[] = [
                    'local_event_id' => $event['local_event_id'] ?? null,
                    'stored' => false,
                    'remote_id' => null,
                    'status' => 'REJECTED',
                    'reason' => 'INVALID_UNIT',
                ];

                AuditLogger::log(
                    event: 'onprem.punch.rejected',
                    auditable: $device,
                    description: 'Punch rejected (invalid unit)',
                    metadata: [
                        'device_serial' => $device->device_serial,
                        'local_event_id' => $event['local_event_id'] ?? null,
                        'reason' => 'INVALID_UNIT',
                        'payload_hash' => $payloadHash,
                        'ip' => $request->ip(),
                    ],
                );
            }

            return response()->json([
                'ok' => true,
                'received' => $results,
            ], 200);
        }

        $deviceUnitId = $device->unit_id;
        if ($deviceUnitId !== null && (int) $deviceUnitId !== $unitId) {
            foreach ($validated['events'] as $event) {
                $results[] = [
                    'local_event_id' => $event['local_event_id'] ?? null,
                    'stored' => false,
                    'remote_id' => null,
                    'status' => 'REJECTED',
                    'reason' => 'INVALID_UNIT',
                ];
            }

            return response()->json([
                'ok' => true,
                'received' => $results,
            ], 200);
        }

        foreach ($validated['events'] as $event) {
            $eventValidator = Validator::make($event, [
                'local_event_id' => ['required', 'uuid'],
                'collaborator_id' => ['nullable', 'integer', 'required_without_all:fortia_employee_id,employee_number,meta.fortia_employee_id'],
                'fortia_employee_id' => ['nullable', 'string', 'max:100'],
                'employee_number' => ['nullable', 'string', 'max:100'],
                'punched_at_local' => ['required', 'date'],
                'timezone' => ['required', 'timezone'],
                'punched_at_utc' => ['required', 'date'],
                'type_inout' => ['required', Rule::in(['IN', 'OUT', 'INOUT'])],
                'source' => ['required', 'string', 'max:80'],
                'quality' => ['nullable', 'integer', 'min:0', 'max:100'],
                'meta' => ['nullable', 'array'],
                'meta.fortia_employee_id' => ['nullable', 'string', 'max:100'],
            ]);

            $localEventId = $event['local_event_id'] ?? null;

            if ($eventValidator->fails()) {
                $results[] = [
                    'local_event_id' => $localEventId,
                    'stored' => false,
                    'remote_id' => null,
                    'status' => 'REJECTED',
                    'reason' => $this->isTimestampValidationFailure($eventValidator) ? 'INVALID_TIMESTAMP' : 'VALIDATION_FAILED',
                ];

                AuditLogger::log(
                    event: 'onprem.punch.rejected',
                    auditable: $device,
                    description: 'Punch rejected',
                    metadata: [
                        'device_serial' => $device->device_serial,
                        'local_event_id' => $localEventId,
                        'reason' => $this->isTimestampValidationFailure($eventValidator) ? 'INVALID_TIMESTAMP' : 'VALIDATION_FAILED',
                        'payload_hash' => $payloadHash,
                        'ip' => $request->ip(),
                        'error' => (string) $eventValidator->errors()->first(),
                    ],
                );

                continue;
            }

            $eventData = $eventValidator->validated();
            $localEventId = (string) $eventData['local_event_id'];

            $employeeResolution = $this->resolveEmployeeForEvent($eventData);
            $employee = $employeeResolution['employee'];

            if ($employeeResolution['reason'] === 'AMBIGUOUS_COLLABORATOR') {
                $results[] = [
                    'local_event_id' => $localEventId,
                    'stored' => false,
                    'remote_id' => null,
                    'status' => 'REJECTED',
                    'reason' => 'AMBIGUOUS_COLLABORATOR',
                ];

                AuditLogger::log(
                    event: 'onprem.punch.rejected',
                    auditable: $device,
                    description: 'Punch rejected',
                    metadata: [
                        'device_serial' => $device->device_serial,
                        'local_event_id' => $localEventId,
                        'reason' => 'AMBIGUOUS_COLLABORATOR',
                        'payload_hash' => $payloadHash,
                        'ip' => $request->ip(),
                        'resolution_strategy' => $employeeResolution['strategy'],
                    ],
                );

                continue;
            }

            if (! $employee) {
                $results[] = [
                    'local_event_id' => $localEventId,
                    'stored' => false,
                    'remote_id' => null,
                    'status' => 'REJECTED',
                    'reason' => 'UNKNOWN_COLLABORATOR',
                ];

                AuditLogger::log(
                    event: 'onprem.punch.rejected',
                    auditable: $device,
                    description: 'Punch rejected',
                    metadata: [
                        'device_serial' => $device->device_serial,
                        'local_event_id' => $localEventId,
                        'reason' => 'UNKNOWN_COLLABORATOR',
                        'payload_hash' => $payloadHash,
                        'ip' => $request->ip(),
                    ],
                );

                continue;
            }

            try {
                $eventTimeUtc = CarbonImmutable::parse($eventData['punched_at_utc'])->utc();
                $tz = (string) $eventData['timezone'];
                $eventTimeLocal = CarbonImmutable::parse($eventData['punched_at_local'], $tz);
                $derivedUtc = $eventTimeLocal->utc();

                if (abs($derivedUtc->diffInSeconds($eventTimeUtc, false)) > 600) {
                    $results[] = [
                        'local_event_id' => $localEventId,
                        'stored' => false,
                        'remote_id' => null,
                        'status' => 'REJECTED',
                        'reason' => 'INVALID_TIMESTAMP',
                    ];

                    AuditLogger::log(
                        event: 'onprem.punch.rejected',
                        auditable: $device,
                        description: 'Punch rejected',
                        metadata: [
                            'device_serial' => $device->device_serial,
                            'local_event_id' => $localEventId,
                            'reason' => 'INVALID_TIMESTAMP',
                            'payload_hash' => $payloadHash,
                            'ip' => $request->ip(),
                        ],
                    );

                    continue;
                }

                $warnings = [];
                if (! empty($unit->timezone) && $unit->timezone !== $tz) {
                    $warnings[] = 'UNIT_TIMEZONE_MISMATCH';
                }

                $existing = AttendanceRaw::query()
                    ->where('device_serial', $device->device_serial)
                    ->where('local_event_id', $localEventId)
                    ->first();

                if ($existing) {
                    $results[] = [
                        'local_event_id' => $localEventId,
                        'stored' => true,
                        'remote_id' => (int) $existing->remote_event_id,
                        'status' => 'DUPLICATE',
                        'reason' => null,
                    ];

                    AuditLogger::log(
                        event: 'onprem.punch.received',
                        auditable: $device,
                        description: 'Punch idempotent',
                        metadata: [
                            'device_serial' => $device->device_serial,
                            'local_event_id' => $localEventId,
                            'remote_id' => (int) $existing->remote_event_id,
                            'status' => 'DUPLICATE',
                            'warnings' => $warnings,
                            'payload_hash' => $payloadHash,
                            'ip' => $request->ip(),
                        ],
                    );

                    continue;
                }

                try {
                    $record = AttendanceRaw::query()->create(
                        [
                            'device_id' => $device->id,
                            'device_serial' => $device->device_serial,
                            'local_event_id' => $localEventId,
                            'collaborator_id' => (int) $eventData['collaborator_id'],
                            'clock_id' => isset($validated['clock_id']) ? (int) $validated['clock_id'] : $device->clock_id,
                            'unit_id' => $unitId,
                            'company_id' => $device->company_id ?? $unit->company_id ?? ($validated['company_id'] ?? null),
                            'event_time_utc' => $eventTimeUtc,
                            'event_time_local' => $eventTimeLocal,
                            'tz' => $tz,
                            'type' => strtoupper((string) $eventData['type_inout']),
                            'source' => (string) $eventData['source'],
                            'meta' => $this->buildMeta(
                                eventData: $eventData,
                                warnings: $warnings,
                                payloadHash: $payloadHash,
                                ingestIp: $request->ip(),
                                requestId: (string) ($request->attributes->get('request_id') ?? ''),
                                authKeyId: 'hmac:'.$device->device_serial,
                                resolutionStrategy: $employeeResolution['strategy'],
                            ),
                        ],
                    );

                    $this->syncCentralAttendanceLog(
                        request: $request,
                        device: $device,
                        unit: $unit,
                        employee: $employee,
                        eventData: $eventData,
                        eventTimeUtc: $eventTimeUtc,
                        localEventId: $localEventId,
                        rawRemoteId: (int) $record->remote_event_id,
                        payloadHash: $payloadHash,
                        resolutionStrategy: $employeeResolution['strategy'],
                    );

                    $results[] = [
                        'local_event_id' => $localEventId,
                        'stored' => true,
                        'remote_id' => (int) $record->remote_event_id,
                        'status' => 'STORED',
                        'reason' => null,
                    ];

                    AuditLogger::log(
                        event: 'onprem.punch.received',
                        auditable: $device,
                        description: 'Punch stored',
                        metadata: [
                            'device_serial' => $device->device_serial,
                            'local_event_id' => $localEventId,
                            'remote_id' => (int) $record->remote_event_id,
                            'status' => 'STORED',
                            'warnings' => $warnings,
                            'payload_hash' => $payloadHash,
                            'ip' => $request->ip(),
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

                    $this->syncCentralAttendanceLog(
                        request: $request,
                        device: $device,
                        unit: $unit,
                        employee: $employee,
                        eventData: $eventData,
                        eventTimeUtc: $eventTimeUtc,
                        localEventId: $localEventId,
                        rawRemoteId: (int) $record->remote_event_id,
                        payloadHash: $payloadHash,
                        resolutionStrategy: $employeeResolution['strategy'],
                    );

                    $results[] = [
                        'local_event_id' => $localEventId,
                        'stored' => true,
                        'remote_id' => (int) $record->remote_event_id,
                        'status' => 'DUPLICATE',
                        'reason' => null,
                    ];

                    AuditLogger::log(
                        event: 'onprem.punch.received',
                        auditable: $device,
                        description: 'Punch idempotent',
                        metadata: [
                            'device_serial' => $device->device_serial,
                            'local_event_id' => $localEventId,
                            'remote_id' => (int) $record->remote_event_id,
                            'status' => 'DUPLICATE',
                            'warnings' => $warnings,
                            'payload_hash' => $payloadHash,
                            'ip' => $request->ip(),
                        ],
                    );
                }
            } catch (Throwable $exception) {
                $results[] = [
                    'local_event_id' => $localEventId,
                    'stored' => false,
                    'remote_id' => null,
                    'status' => 'REJECTED',
                    'reason' => 'VALIDATION_FAILED',
                ];

                AuditLogger::log(
                    event: 'onprem.punch.rejected',
                    auditable: $device,
                    description: 'Punch rejected',
                    metadata: [
                        'device_serial' => $device->device_serial,
                        'local_event_id' => $localEventId,
                        'reason' => 'VALIDATION_FAILED',
                        'payload_hash' => $payloadHash,
                        'ip' => $request->ip(),
                        'error' => $exception->getMessage(),
                    ],
                );
            }
        }

        return response()->json([
            'ok' => true,
            'received' => $results,
        ], 200);
    }

    private function syncCentralAttendanceLog(
        StoreOnPremAttendancesRequest $request,
        Device $device,
        Location $unit,
        Employee $employee,
        array $eventData,
        CarbonImmutable $eventTimeUtc,
        string $localEventId,
        int $rawRemoteId,
        string $payloadHash,
        ?string $resolutionStrategy = null,
    ): void {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        $clockId = (int) ($device->clock_id ?? 0);
        $existing = null;

        if ($this->hasAttendanceLogsColumn('local_id')) {
            $existing = AttendanceRecord::query()
                ->where('local_id', $localEventId)
                ->where('device_id', $clockId > 0 ? $clockId : null)
                ->first();
        }

        if (! $existing) {
            $existing = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->where('device_id', $clockId > 0 ? $clockId : null)
                ->where('log_date', $eventTimeUtc)
                ->first();
        }

        if ($existing) {
            return;
        }

        $payload = [
            'log_id' => ((int) (AttendanceRecord::query()->max('log_id') ?? 0)) + 1,
            'employee_id' => $employee->id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'company_id' => $device->company_id ?? $unit->company_id,
            'location_id' => $unit->id,
            'device_id' => $clockId > 0 ? $clockId : null,
            'log_date' => $eventTimeUtc,
            'log_type' => $this->mapInOutToLogType((string) $eventData['type_inout']),
        ];

        if ($this->hasAttendanceLogsColumn('local_id')) {
            $payload['local_id'] = $localEventId;
        }
        if ($this->hasAttendanceLogsColumn('source')) {
            $payload['source'] = AttendanceRecord::SOURCE_API;
        }
        if ($this->hasAttendanceLogsColumn('attendance_status')) {
            $payload['attendance_status'] = AttendanceRecord::STATUS_VALIDA;
        }
        if ($this->hasAttendanceLogsColumn('status')) {
            $payload['status'] = 1;
        }
        if ($this->hasAttendanceLogsColumn('raw_payload')) {
            $payload['raw_payload'] = [
                'provider' => 'onprem',
                'type_inout' => $eventData['type_inout'] ?? null,
                'timezone' => $eventData['timezone'] ?? null,
                'punched_at_local' => $eventData['punched_at_local'] ?? null,
                'punched_at_utc' => $eventData['punched_at_utc'] ?? null,
                'source' => $eventData['source'] ?? null,
                'quality' => $eventData['quality'] ?? null,
                'raw_remote_id' => $rawRemoteId,
                'payload_hash' => $payloadHash,
                'resolution_strategy' => $resolutionStrategy,
                'meta' => $this->buildCentralRawPayloadMeta($eventData['meta'] ?? null, $resolutionStrategy),
            ];
        }
        if ($this->hasAttendanceLogsColumn('ingested_at_utc')) {
            $payload['ingested_at_utc'] = now('UTC');
        }
        if ($this->hasAttendanceLogsColumn('ingest_ip')) {
            $payload['ingest_ip'] = $request->ip();
        }
        if ($this->hasAttendanceLogsColumn('device_serial')) {
            $payload['device_serial'] = $device->device_serial;
        }
        if ($this->hasAttendanceLogsColumn('auth_key_id')) {
            $payload['auth_key_id'] = 'hmac:'.$device->device_serial;
        }
        if ($this->hasAttendanceLogsColumn('request_id')) {
            $payload['request_id'] = (string) ($request->attributes->get('request_id') ?? null);
        }

        try {
            AttendanceRecord::query()->create($payload);
        } catch (QueryException $exception) {
            if ($this->hasAttendanceLogsColumn('local_id')) {
                $already = AttendanceRecord::query()
                    ->where('local_id', $localEventId)
                    ->where('device_id', $clockId > 0 ? $clockId : null)
                    ->exists();

                if ($already) {
                    return;
                }
            }

            throw $exception;
        }
    }

    private function mapInOutToLogType(string $typeInOut): int
    {
        $value = strtoupper(trim($typeInOut));

        return match ($value) {
            'IN' => 1,
            'OUT' => 2,
            default => 0,
        };
    }

    private function hasAttendanceLogsColumn(string $column): bool
    {
        static $cache = [];

        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $cache[$column] = Schema::hasColumn('attendance_logs', $column);

        return $cache[$column];
    }

    private function buildMeta(
        array $eventData,
        array $warnings,
        string $payloadHash,
        ?string $ingestIp,
        ?string $requestId,
        string $authKeyId,
        ?string $resolutionStrategy = null,
    ): ?array
    {
        $meta = $eventData['meta'] ?? [];
        if (! is_array($meta)) {
            $meta = [];
        }

        if (array_key_exists('quality', $eventData) && $eventData['quality'] !== null) {
            $meta['quality'] = (int) $eventData['quality'];
        }

        if ($warnings !== []) {
            $meta['_warnings'] = $warnings;
        }

        if ($resolutionStrategy !== null) {
            $meta['_resolution'] = [
                'strategy' => $resolutionStrategy,
            ];
        }

        $meta['_ingest'] = [
            'payload_hash' => $payloadHash,
            'ingest_ip' => $ingestIp,
            'request_id' => $requestId ?: null,
            'auth_key_id' => $authKeyId,
            'captured_at_utc' => now('UTC')->toIso8601String(),
        ];

        return $meta === [] ? null : $meta;
    }

    /**
     * @return array{employee:?Employee,strategy:?string,reason:?string}
     */
    private function resolveEmployeeForEvent(array $eventData): array
    {
        $operationalReference = $this->resolveOperationalEmployeeReference($eventData);

        if ($operationalReference !== null) {
            $query = Employee::query()->where('fortia_employee_id', $operationalReference);

            if (ctype_digit($operationalReference)) {
                $query->orWhere('fortia_employee_id', (int) $operationalReference);
            }

            return [
                'employee' => $query->first(),
                'strategy' => $this->resolveOperationalEmployeeStrategy($eventData),
                'reason' => null,
            ];
        }

        if (! isset($eventData['collaborator_id']) || $eventData['collaborator_id'] === null) {
            return [
                'employee' => null,
                'strategy' => null,
                'reason' => null,
            ];
        }

        $collaboratorId = (int) $eventData['collaborator_id'];
        $byId = Employee::query()->where('id', $collaboratorId)->first();
        $byFortia = Employee::query()
            ->where('fortia_employee_id', $collaboratorId)
            ->first();

        if ($byId && $byFortia && $byId->id !== $byFortia->id) {
            return [
                'employee' => null,
                'strategy' => 'legacy_ambiguous',
                'reason' => 'AMBIGUOUS_COLLABORATOR',
            ];
        }

        if ($byId) {
            return [
                'employee' => $byId,
                'strategy' => 'legacy_collaborator_id_internal',
                'reason' => null,
            ];
        }

        if ($byFortia) {
            return [
                'employee' => $byFortia,
                'strategy' => 'legacy_collaborator_id_fortia',
                'reason' => null,
            ];
        }

        return [
            'employee' => null,
            'strategy' => 'legacy_collaborator_id_unresolved',
            'reason' => null,
        ];
    }

    private function resolveOperationalEmployeeReference(array $eventData): ?string
    {
        $candidates = [
            $eventData['fortia_employee_id'] ?? null,
            $eventData['employee_number'] ?? null,
            is_array($eventData['meta'] ?? null) ? ($eventData['meta']['fortia_employee_id'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $value = trim((string) $candidate);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveOperationalEmployeeStrategy(array $eventData): ?string
    {
        $candidates = [
            'fortia_employee_id' => $eventData['fortia_employee_id'] ?? null,
            'employee_number' => $eventData['employee_number'] ?? null,
            'meta.fortia_employee_id' => is_array($eventData['meta'] ?? null) ? ($eventData['meta']['fortia_employee_id'] ?? null) : null,
        ];

        foreach ($candidates as $strategy => $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (trim((string) $candidate) !== '') {
                return $strategy;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $meta
     * @return array<string, mixed>|null
     */
    private function buildCentralRawPayloadMeta($meta, ?string $resolutionStrategy): ?array
    {
        $normalized = is_array($meta) ? $meta : [];

        if ($resolutionStrategy !== null) {
            $normalized['_resolution'] = [
                'strategy' => $resolutionStrategy,
            ];
        }

        return $normalized === [] ? null : $normalized;
    }

    private function isTimestampValidationFailure(\Illuminate\Contracts\Validation\Validator $validator): bool
    {
        $failed = $validator->failed();

        return isset($failed['punched_at_local']) || isset($failed['punched_at_utc']) || isset($failed['timezone']);
    }

    private function errorResponse(int $status, string $reason, string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $reason,
            'reason' => $reason,
            'message' => $message,
        ], $status);
    }
}
