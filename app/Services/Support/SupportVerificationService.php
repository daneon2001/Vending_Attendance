<?php

namespace App\Services\Support;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\SupportVerification;
use App\Services\Audit\AuditLogger;
use App\Services\Vending\DeviceFleetHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportVerificationService
{
    private const CLIENT_CHECKS = [
        'GPS_PERMISSION', 'GPS_AVAILABILITY', 'CAMERA_PERMISSION', 'CAMERA_AVAILABILITY',
        'NETWORK', 'API_REACHABILITY', 'LOCAL_CONFIGURATION', 'LOCAL_EMPLOYEES', 'LOCAL_OUTBOX',
    ];

    public function __construct(
        private readonly SupportAccess $access,
        private readonly SupportOperations $operations,
        private readonly DeviceFleetHealthService $health,
        private readonly SupportAutomationService $automation,
    ) {}

    public function capture(SupportActor $actor, array $input): array
    {
        $this->access->authorize($actor, 'verify');
        $unknown = array_diff(array_keys($input), [
            'client_operation_uuid', 'device_id', 'started_at', 'completed_at',
            'captured_machine_uuid',
            'app_version', 'app_build_number', 'checks',
        ]);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['verification' => 'La verificación contiene campos no permitidos.']);
        }
        $data = Validator::make($input, [
            'client_operation_uuid' => ['required', 'uuid'],
            'captured_machine_uuid' => $actor->kind === 'device' ? ['sometimes', 'uuid'] : ['prohibited'],
            'device_id' => ['nullable', 'integer', 'min:1'],
            'started_at' => ['required', 'date', 'after_or_equal:2000-01-01'],
            'completed_at' => ['required', 'date', 'after_or_equal:started_at'],
            'app_version' => ['nullable', 'string', 'max:80', 'regex:/\A[0-9A-Za-z.+_-]+\z/'],
            'app_build_number' => ['nullable', 'integer', 'min:1'],
            'checks' => ['present', 'array', 'max:9'],
            'checks.*' => ['array:code,result,observed_at,details'],
            'checks.*.code' => ['required', 'distinct', Rule::in(self::CLIENT_CHECKS)],
            'checks.*.result' => ['required', Rule::in(['PASS', 'WARNING', 'FAIL', 'NOT_AVAILABLE'])],
            'checks.*.observed_at' => ['nullable', 'date'],
            'checks.*.details' => ['sometimes', 'array:permission,available,state,version,pending_count,error_code'],
            'checks.*.details.permission' => ['sometimes', Rule::in(['granted', 'denied', 'prompt', 'unknown'])],
            'checks.*.details.available' => ['sometimes', 'boolean'],
            'checks.*.details.state' => ['sometimes', Rule::in(['ONLINE', 'OFFLINE', 'UNKNOWN'])],
            'checks.*.details.version' => ['sometimes', 'integer', 'min:1'],
            'checks.*.details.pending_count' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'checks.*.details.error_code' => ['sometimes', Rule::in([
                'GPS_PERMISSION_DENIED', 'GPS_UNAVAILABLE', 'GPS_TIMEOUT', 'GPS_DISABLED',
                'NETWORK_TIMEOUT', 'AUTHENTICATION_FAILED', 'NOT_PROVISIONED',
                'CAMERA_UNAVAILABLE', 'CAMERA_PERMISSION_DENIED',
            ])],
        ])->validate();
        $started = Carbon::parse($data['started_at'])->utc();
        $completed = Carbon::parse($data['completed_at'])->utc();
        if ($completed->gt(now()->addMinutes(5)) || $started->diffInSeconds($completed) > 3600) {
            throw ValidationException::withMessages(['completed_at' => 'El periodo de verificación no es válido.']);
        }
        $deviceId = $actor->kind === 'device' ? $actor->id : (int) ($data['device_id'] ?? 0);
        if ($actor->kind === 'device' && isset($data['device_id']) && (int) $data['device_id'] !== $deviceId) {
            abort(403);
        }
        $device = $this->access->device($actor, $deviceId);
        $machine = $this->access->machine($actor, (int) $device->vending_machine_id);
        $this->access->assertCapturedMachine($actor, $machine, $data['captured_machine_uuid'] ?? null);
        abort_if($this->access->reservedForFuturePhysicalTest($machine), 403, 'El equipo está reservado para otra validación.');
        $checks = [];
        foreach ($data['checks'] as $check) {
            $observed = Carbon::parse($check['observed_at'] ?? $data['completed_at'])->utc();
            if ($observed->lt($started) || $observed->gt($completed)) {
                throw ValidationException::withMessages(['checks' => 'La observación debe pertenecer a la sesión.']);
            }
            $checks[] = [
                'code' => $check['code'], 'result' => $check['result'], 'source' => 'CLIENT_REPORTED',
                'observed_at' => $observed->toIso8601String(), 'details' => $check['details'] ?? [],
            ];
        }
        $fingerprint = [
            'action' => 'verification.capture', 'device_id' => $deviceId,
            'vending_machine_id' => $machine->id, 'captured_machine_uuid' => $data['captured_machine_uuid'] ?? null,
            'started_at' => $started->toIso8601String(), 'completed_at' => $completed->toIso8601String(),
            'app_version' => $data['app_version'] ?? null, 'app_build_number' => $data['app_build_number'] ?? null,
            'checks' => $checks,
        ];

        return $this->operations->run($actor, $data['client_operation_uuid'], $fingerprint, function () use ($actor, $device, $data, $started, $completed, $checks): array {
            $this->access->lockDeviceContext($actor, (int) $device->vending_machine_id);
            $device->load(['vendingMachine', 'manifestStates']);
            $allChecks = [...$this->serverChecks($device), ...$checks];
            $results = array_column($allChecks, 'result');
            $summary = in_array('FAIL', $results, true) ? 'FAIL'
                : (in_array('WARNING', $results, true) ? 'WARNING'
                    : (in_array('NOT_AVAILABLE', $results, true) ? 'NOT_AVAILABLE' : 'PASS'));
            $verification = SupportVerification::query()->create([
                'uuid' => (string) Str::uuid(), 'vending_machine_id' => $device->vending_machine_id,
                'device_id' => $device->id, 'actor_kind' => $actor->kind, 'actor_id' => $actor->id,
                'app_version' => $data['app_version'] ?? null, 'app_build_number' => $data['app_build_number'] ?? null,
                'started_at' => $started, 'completed_at' => $completed, 'summary' => $summary, 'checks' => $allChecks,
            ]);
            AuditLogger::log('support.verification.created', $verification, 'Verificación técnica registrada.', [
                'device_id' => $device->id, 'support_actor_kind' => $actor->kind,
                'support_actor_id' => $actor->id, 'summary' => $summary,
            ]);
            $ticket = $this->automation->evaluateVerification($verification);
            if ($ticket) {
                $verification->update(['ticket_id' => $ticket->id]);
            }

            return ['verification' => [
                'uuid' => $verification->uuid, 'summary' => $summary,
                'started_at' => $started->toIso8601String(), 'completed_at' => $completed->toIso8601String(),
                'checks' => $allChecks, 'ticket_uuid' => $ticket?->uuid, 'ticket_folio' => $ticket?->folio,
            ]];
        });
    }

    private function serverChecks(Device $device): array
    {
        $now = now();
        $health = $this->health->evaluate($device, $now);
        $fresh = in_array($health['status'], ['ONLINE', 'DEGRADED'], true);
        $observed = $now->utc()->toIso8601String();
        $check = static fn (string $code, string $result, array $details = []): array => [
            'code' => $code, 'result' => $result, 'source' => 'SERVER_SNAPSHOT',
            'observed_at' => $observed, 'details' => $details,
        ];
        $signal = static fn (string $reason, mixed $value): string => ! $fresh || $value === null
            ? 'NOT_AVAILABLE' : (in_array($reason, $health['reasons'], true) ? 'WARNING' : 'PASS');
        $manifest = static fn (array $state): string => ! $fresh ? 'NOT_AVAILABLE' : match ($state['state']) {
            'SYNCED' => 'PASS', 'ERROR' => 'FAIL', default => 'WARNING',
        };

        return [
            $check('API_RECEIPT', 'PASS'),
            $check('DEVICE_ACTIVE', $device->status === DeviceStatus::ACTIVE ? 'PASS' : 'FAIL'),
            $check('MACHINE_ASSOCIATION', 'PASS'),
            $check('HEARTBEAT', $health['heartbeat_age_seconds'] === null ? 'NOT_AVAILABLE'
                : ($health['status'] === 'OFFLINE' ? 'FAIL' : ($fresh ? 'PASS' : 'NOT_AVAILABLE')),
                ['age_seconds' => $health['heartbeat_age_seconds']]),
            $check('APP_VERSION', ! $fresh ? 'NOT_AVAILABLE' : match ($health['app_version']['status']) {
                'CURRENT' => 'PASS', 'UNSUPPORTED' => 'FAIL', 'UNKNOWN' => 'NOT_AVAILABLE', default => 'WARNING',
            }, ['status' => $health['app_version']['status']]),
            $check('CONFIGURATION_MANIFEST', $manifest($health['manifest']['configuration']),
                ['state' => $health['manifest']['configuration']['state']]),
            $check('EMPLOYEE_MANIFEST', $manifest($health['manifest']['employees']),
                ['state' => $health['manifest']['employees']['state']]),
            $check('OUTBOX', $signal('OUTBOX_PRESSURE', $device->pending_events_count), ['pending_count' => $device->pending_events_count]),
            $check('CLOCK_DRIFT', $signal('CLOCK_DRIFT', $device->clock_drift_seconds), ['seconds' => $device->clock_drift_seconds]),
            $check('STORAGE', $signal('LOW_STORAGE', $device->storage_free_mb), ['free_mb' => $device->storage_free_mb]),
        ];
    }
}
