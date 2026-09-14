<?php

namespace App\Services\Support;

use App\Enums\Support\SupportActivityStatus as Status;
use App\Enums\Support\SupportActivityType as Type;
use App\Enums\Vending\GeofenceValidationResult;
use App\Models\Employee;
use App\Models\MachineGeofence;
use App\Models\User;
use App\Models\VendingMachine;
use App\Models\VendingSupportActivity;
use App\Services\Audit\AuditLogger;
use App\Services\Vending\GeofenceValidationService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportActivityService
{
    public function __construct(
        private readonly SupportActivityAccess $access,
        private readonly GeofenceValidationService $geofences,
        private readonly SupportOperations $operations,
        private readonly SupportActivityPresencePolicy $presence,
    ) {}

    public function create(array $input): VendingSupportActivity
    {
        return DB::transaction(function () use ($input): VendingSupportActivity {
            [$user, $employee] = $this->access->identity();
            $this->access->permission($user, 'assign');
            $data = Validator::make($input, [
                'client_operation_uuid' => ['required', 'uuid'],
                'vending_machine_id' => ['required', 'integer', 'min:1'],
                'employee_id' => ['required', 'integer', 'min:1'],
                'support_ticket_uuid' => ['nullable', 'uuid'],
                'activity_type' => ['required', Rule::enum(Type::class)],
                'title' => ['required', 'string', 'max:160'],
                'description' => ['nullable', 'string', 'max:4000'],
                'scheduled_at' => ['nullable', 'date'],
                'requires_physical_presence' => ['prohibited'],
                'status' => ['prohibited'], 'uuid' => ['prohibited'],
                'created_by_user_id' => ['prohibited'], 'assigned_by_user_id' => ['prohibited'],
            ])->validate();
            $machine = VendingMachine::query()->whereKey($data['vending_machine_id'])->lockForUpdate()->firstOrFail();
            $type = Type::from($data['activity_type']);
            $this->access->machine($employee, $machine);
            // The supplied assignee is a selection, never proof of authorization.
            $target = Employee::query()->findOrFail($data['employee_id']);
            $this->access->machine($target, $machine, $type);
            $ticket = null;
            if (! empty($data['support_ticket_uuid'])) {
                $actor = SupportActor::user($user);
                $ticket = app(SupportAccess::class)->ticket($actor, $data['support_ticket_uuid']);
                app(SupportAccess::class)->authorize($actor, 'read', $ticket);
                abort_unless((int) $ticket->vending_machine_id === (int) $machine->id, 422, 'El reporte pertenece a otra máquina.');
            }
            $attributes = [
                'vending_machine_id' => $machine->id,
                'employee_id' => $target->id, 'support_ticket_id' => $ticket?->id,
                'assigned_by_user_id' => $user->id, 'created_by_user_id' => $user->id,
                'activity_type' => $type->value,
                'title' => $data['title'], 'description' => $data['description'] ?? null,
                'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at'])->utc()->toDateTimeString() : null,
            ];
            // Live authorization above applies to replays too. Reuse the persistent
            // UNIQUE(principal_key, operation_uuid) receipt and canonical content hash.
            $receipt = $this->operations->run(SupportActor::user($user), $data['client_operation_uuid'], [
                'intent' => 'support_activity.create', 'actor_employee_id' => $employee->id,
                'attributes' => $attributes,
            ], function () use ($attributes, $type, $user, $employee): array {
                $activity = new VendingSupportActivity;
                $activity->forceFill($attributes + [
                    'uuid' => (string) Str::uuid(), 'status' => Status::ASSIGNED,
                    'presence_policy' => SupportActivityPresencePolicy::CURRENT,
                    'requires_physical_presence' => $this->presence->requiresPhysicalPresence($type),
                ])->save();
                $this->record($activity, 'created', $user, $employee);
                $this->record($activity, 'assigned', $user, $employee);

                return ['activity_uuid' => $activity->uuid];
            });

            // Current read: under MySQL REPEATABLE READ the initial identity query
            // may predate the competing creator's commit, although its locked receipt is visible.
            return VendingSupportActivity::query()->where('uuid', $receipt['activity_uuid'])->lockForUpdate()->firstOrFail();
        }, 3);
    }

    public function start(string $uuid, array $location): VendingSupportActivity
    {
        return $this->transition($uuid, 'start', $location);
    }

    public function complete(string $uuid): VendingSupportActivity
    {
        return $this->transition($uuid, 'complete');
    }

    public function cancel(string $uuid, string $reason): VendingSupportActivity
    {
        return $this->transition($uuid, 'cancel', ['cancellation_reason' => $reason]);
    }

    private function transition(string $uuid, string $action, array $input = []): VendingSupportActivity
    {
        return DB::transaction(function () use ($uuid, $action, $input): VendingSupportActivity {
            [$user, $employee] = $this->access->identity();
            $activity = VendingSupportActivity::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $machine = VendingMachine::query()->whereKey($activity->vending_machine_id)->lockForUpdate()->firstOrFail();
            // Authorize before revealing state, including replay attempts.
            if ($action === 'cancel' && $user->hasPermission('support', 'assign')) {
                $this->access->machine($employee, $machine);
            } else {
                $this->access->execute($activity, $user, $employee, $machine);
            }
            $from = $activity->status;
            $valid = match ($action) {
                'start' => $from === Status::ASSIGNED,
                'complete' => $from === Status::IN_PROGRESS,
                'cancel' => in_array($from, [Status::ASSIGNED, Status::IN_PROGRESS], true),
            };
            abort_unless($valid, 409, 'La actividad ya cambió de estado. Actualiza el detalle.');
            $at = now('UTC');
            if ($action === 'start') {
                $changes = $this->locationSnapshot($activity, $machine, $input) + [
                    'status' => Status::IN_PROGRESS->value, 'started_at' => $at, 'started_by_user_id' => $user->id,
                ];
                $kind = 'started';
            } elseif ($action === 'complete') {
                abort_unless((int) $activity->started_by_user_id === (int) $user->id, 403, 'Sólo quien inició puede finalizar esta actividad.');
                $changes = ['status' => Status::COMPLETED->value, 'completed_at' => $at, 'completed_by_user_id' => $user->id];
                $kind = 'completed';
            } else {
                $data = Validator::make($input, ['cancellation_reason' => ['required', 'string', 'max:1000']])->validate();
                if (trim($data['cancellation_reason']) === '') {
                    throw ValidationException::withMessages(['cancellation_reason' => 'Indica el motivo de cancelación.']);
                }
                $changes = ['status' => Status::CANCELLED->value, 'cancelled_at' => $at,
                    'cancelled_by_user_id' => $user->id, 'cancellation_reason' => trim($data['cancellation_reason'])];
                $kind = 'cancelled';
            }
            // CAS complements the row lock; a stale caller cannot transition twice.
            $updated = VendingSupportActivity::query()->whereKey($activity->id)->where('status', $from->value)
                ->update($changes + ['updated_at' => $at]);
            abort_unless($updated === 1, 409, 'La actividad cambió. Actualiza el detalle.');
            $activity->refresh();
            $this->record($activity, $kind, $user, $employee);

            return $activity;
        }, 3);
    }

    private function locationSnapshot(VendingSupportActivity $activity, VendingMachine $machine, array $input): array
    {
        // Remote execution requires a future server policy; a changed row/client flag cannot bypass v1.
        abort_unless($activity->requires_physical_presence
            && $this->presence->requiresPhysicalPresence($activity->activity_type, $activity->presence_policy),
            409, 'Política de presencia no disponible.');
        $gps = Validator::make($input, [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'between:0,100000'],
            'captured_at' => ['required', 'date'],
        ])->validate();
        $capturedAt = Carbon::parse($gps['captured_at'])->utc();
        // Technical online freshness policy v1, not an attendance/labor rule.
        if ($capturedAt->lt(now('UTC')->subMinutes(5)) || $capturedAt->gt(now('UTC')->addSeconds(30))) {
            throw ValidationException::withMessages(['captured_at' => 'Obtén una ubicación reciente para iniciar.']);
        }
        foreach (['latitude', 'longitude', 'accuracy_m'] as $field) {
            if (! is_finite((float) $gps[$field])) {
                throw ValidationException::withMessages([$field => 'La ubicación no es válida.']);
            }
        }
        // Same machine lock used by MachineGeofenceService protects active-version selection.
        $geofence = MachineGeofence::query()->where('vending_machine_id', $machine->id)->effectiveAt()
            ->lockForUpdate()->first();
        if ($geofence === null) {
            $this->locationDenied('NOT_EVALUATED', 'La máquina no tiene una zona activa vigente. Solicita ayuda de soporte.');
        }
        try {
            $evaluation = $this->geofences->validate($geofence, (float) $gps['latitude'], (float) $gps['longitude'], (float) $gps['accuracy_m'], $capturedAt);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['location' => 'La ubicación o la zona configurada no son válidas.']);
        }
        if ($evaluation['result'] !== GeofenceValidationResult::INSIDE->value) {
            $this->locationDenied($evaluation['result'], $evaluation['result'] === GeofenceValidationResult::OUTSIDE->value
                ? 'Estás fuera de la zona permitida. Acércate a la máquina para iniciar.'
                : 'No fue posible confirmar tu ubicación. Obtén una ubicación más precisa e intenta nuevamente.');
        }

        return [
            'started_latitude' => $gps['latitude'], 'started_longitude' => $gps['longitude'],
            'started_accuracy_m' => $gps['accuracy_m'], 'started_captured_at' => $capturedAt,
            'machine_geofence_id' => $geofence->id, 'geofence_version' => $geofence->version,
            'distance_m' => $evaluation['distance_m'], 'effective_distance_m' => $evaluation['effective_distance_m'],
            'geofence_result' => $evaluation['result'], 'geofence_evaluated_at' => now('UTC'),
        ];
    }

    private function locationDenied(string $result, string $message): never
    {
        throw new HttpResponseException(response()->json(['geofence_result' => $result, 'message' => $message], 422));
    }

    private function record(VendingSupportActivity $activity, string $kind, User $user, Employee $employee): void
    {
        // Serialize event allocation with the existing projector so later commits cannot skip earlier IDs.
        DB::table('support_runtime_cursors')->where('key', 'notification_sequence')->lockForUpdate()->firstOrFail();
        DB::table('vending_support_activity_events')->insert([
            'activity_id' => $activity->id, 'kind' => $kind, 'user_id' => $user->id,
            'employee_id' => $employee->id, 'occurred_at' => now('UTC'),
        ]);
        // No coordinates, description, cancellation text, payload or secrets in general logs.
        AuditLogger::log('support_activity.'.$kind, $activity, 'Cambio de actividad de campo', [
            'activity_uuid' => $activity->uuid, 'employee_id' => $employee->id,
            'vending_machine_id' => $activity->vending_machine_id, 'status' => $activity->status->value,
        ]);
        // Reuse the bounded, recoverable in-app projector; delivery never changes the transition.
        DB::afterCommit(static function (): void {
            try {
                app(SupportNotificationService::class)->publish(20, 100);
            } catch (\Throwable) {
                // support:process-events resumes persistent cursors, without sensitive logging.
            }
        });
    }
}
