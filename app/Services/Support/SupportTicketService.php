<?php

namespace App\Services\Support;

use App\Models\SupportIntegration;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Vending\GeofenceValidationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportTicketService
{
    private const TRANSITIONS = [
        'OPEN' => ['IN_PROGRESS', 'WAITING', 'RESOLVED', 'CANCELLED'],
        'IN_PROGRESS' => ['WAITING', 'RESOLVED', 'CANCELLED'],
        'WAITING' => ['IN_PROGRESS', 'RESOLVED', 'CANCELLED'],
        'RESOLVED' => ['CLOSED'],
    ];

    public function allowedTransitions(SupportActor $actor, SupportTicket $ticket): array
    {
        return array_values(array_filter(self::TRANSITIONS[$ticket->status] ?? [], function (string $status) use ($actor, $ticket): bool {
            $action = match ($status) {
                'RESOLVED' => 'resolve', 'CLOSED', 'CANCELLED' => 'manage', default => 'transition'
            };
            try {
                $this->access->authorize($actor, $action, $ticket);

                return true;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                if ($exception->getStatusCode() !== 403) {
                    throw $exception;
                }

                return false;
            }
        }));
    }

    public function __construct(
        private readonly SupportAccess $access,
        private readonly SupportOperations $operations,
        private readonly SupportTimeline $timeline,
        private readonly SupportPresenter $presenter,
        private readonly GeofenceValidationService $geofences,
        private readonly SupportSlaService $sla,
    ) {}

    public function create(SupportActor $actor, array $input): array
    {
        $this->access->authorize($actor, 'create');
        $data = Validator::make($input, [
            'client_operation_uuid' => 'required|uuid',
            'captured_machine_uuid' => $actor->kind === 'device' ? 'sometimes|uuid' : 'prohibited',
            'vending_machine_id' => $actor->kind === 'device' ? 'prohibited' : 'required|integer',
            'device_id' => $actor->kind === 'device' ? 'prohibited' : 'nullable|integer',
            'reporter_employee_id' => 'prohibited', // A selected employee is not a verified reporter.
            'external_system' => 'prohibited',
            'external_reference' => $actor->kind === 'integration' ? 'nullable|string|max:160|regex:/^[A-Za-z0-9._:\\/-]+$/' : 'prohibited',
            'source' => $actor->kind === 'system' ? ['required', Rule::in(['AUTOMATED_ALERT', 'DEVICE_VERIFICATION', 'SYSTEM'])] : 'prohibited',
            'category' => ['required', Rule::in(array_keys(config('support.categories')))],
            'severity' => ['sometimes', Rule::in(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])],
            'priority' => ['sometimes', Rule::in(['LOW', 'NORMAL', 'HIGH', 'URGENT'])],
            'title' => 'required|string|max:160',
            'description' => 'required|string|max:5000',
            'reported_at' => 'required_with:location|date|after:2000-01-01',
            'location' => 'nullable|array:latitude,longitude,accuracy_m,captured_at',
            'location.latitude' => 'required_with:location|numeric|between:-90,90',
            'location.longitude' => 'required_with:location|numeric|between:-180,180',
            'location.accuracy_m' => 'required_with:location|numeric|between:0,100000',
            'location.captured_at' => 'required_with:location|date',
        ])->validate();
        $machineId = $actor->kind === 'device' ? (int) $actor->model->vending_machine_id : (int) $data['vending_machine_id'];
        $machine = $this->access->machine($actor, $machineId);
        $this->access->assertCapturedMachine($actor, $machine, $data['captured_machine_uuid'] ?? null);
        $device = $actor->kind === 'device' ? $actor->model : (isset($data['device_id']) ? $this->access->device($actor, (int) $data['device_id']) : null);
        abort_if($device && $device->vending_machine_id !== $machine->id, 422, 'El equipo no corresponde a la máquina.');
        $reportedAt = Carbon::parse($data['reported_at'] ?? now('UTC'))->utc();
        abort_if($reportedAt->gt(now('UTC')->addMinutes(5)), 422, 'La fecha del reporte no es válida.');
        $location = $data['location'] ?? null;
        if ($location !== null) {
            $capturedAt = Carbon::parse($location['captured_at'])->utc();
            abort_if(abs($capturedAt->diffInSeconds($reportedAt, false)) > 120
                || ((float) $location['latitude'] === 0.0 && (float) $location['longitude'] === 0.0),
                422, 'La ubicación no corresponde a una captura reciente del reporte.');
        }
        $creationFingerprint = $this->operations->fingerprint([
            'machine_id' => $machine->id, 'device_id' => $device?->id,
            'category' => $data['category'], 'title' => trim($data['title']), 'description' => trim($data['description']),
            'severity' => $data['severity'] ?? 'MEDIUM', 'priority' => $data['priority'] ?? 'NORMAL',
            'reported_at' => isset($data['reported_at']) ? $reportedAt->toISOString() : null,
            'location' => $location === null ? null : [
                'latitude' => (float) $location['latitude'], 'longitude' => (float) $location['longitude'],
                'accuracy_m' => (float) $location['accuracy_m'],
                'captured_at' => Carbon::parse($location['captured_at'])->utc()->toISOString(),
            ],
        ]);

        return $this->operations->run($actor, $data['client_operation_uuid'], ['action' => 'create', 'machine_id' => $machine->id, 'device_id' => $device?->id, 'data' => $data], function () use ($actor, $data, $machine, $device, $reportedAt, $location, $creationFingerprint): array {
            $this->access->lockDeviceContext($actor, $machine->id);
            if ($actor->kind === 'integration') {
                SupportIntegration::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if (isset($data['external_reference'])) {
                    $existing = SupportTicket::query()->where('integration_id', $actor->id)->where('external_reference', $data['external_reference'])->first();
                    if ($existing) {
                        abort_unless(is_string($existing->creation_fingerprint)
                            && hash_equals($existing->creation_fingerprint, $creationFingerprint),
                            409, 'La referencia externa ya tiene otro reporte.');

                        return ['ticket' => $this->presenter->ticket($existing)];
                    }
                }
            }
            $geofence = $location !== null ? $machine->activeGeofence()->first() : null;
            $context = $geofence ? $this->geofences->validate($geofence, (float) $location['latitude'], (float) $location['longitude'], (float) $location['accuracy_m'], $location['captured_at']) : null;
            $ticket = SupportTicket::query()->create([
                'uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id, 'device_id' => $device?->id,
                'creation_fingerprint' => $creationFingerprint,
                'reporter_user_id' => $actor->kind === 'user' ? $actor->id : null,
                'integration_id' => $actor->kind === 'integration' ? $actor->id : null,
                'external_reference' => $data['external_reference'] ?? null,
                'source' => match ($actor->kind) {
                    'device' => 'MANUAL_MOBILE', 'user' => 'MANUAL_WEB', 'integration' => 'INTEGRATION', default => $data['source']
                },
                'category' => $data['category'], 'severity' => $data['severity'] ?? 'MEDIUM',
                'priority' => $data['priority'] ?? 'NORMAL', 'status' => 'OPEN', 'title' => trim($data['title']),
                'description' => trim($data['description']), 'reported_at' => $reportedAt,
                'location' => $location, 'geofence_context' => $context,
            ]);
            $this->sla->initialize($ticket);
            $ticket->save();
            $this->timeline->append($ticket, $actor, 'support.ticket.created');

            return ['ticket' => $this->presenter->ticket($ticket->fresh())];
        });
    }

    public function comment(SupportActor $actor, string $uuid, array $input): array
    {
        $data = Validator::make($input, ['client_operation_uuid' => 'required|uuid', 'body' => 'required|string|max:5000'])->validate();

        return $this->mutate($actor, $uuid, 'comment', $data, function (SupportTicket $ticket) use ($actor, $data): void {
            $this->timeline->append($ticket, $actor, 'support.comment.created', trim($data['body']));
            $this->firstResponse($ticket, $actor);
        });
    }

    public function assign(SupportActor $actor, string $uuid, array $input): array
    {
        $data = Validator::make($input, ['client_operation_uuid' => 'required|uuid', 'assignee_id' => 'present|nullable|integer'])->validate();
        $assignee = $data['assignee_id'] === null ? null : User::query()->with('roles.permissions')->whereKey($data['assignee_id'])->where('estatus', true)->first();
        abort_if($data['assignee_id'] !== null && (! $assignee || ! $assignee->hasPermission('support', 'resolve')), 422, 'Selecciona una persona habilitada para atender soporte.');

        return $this->mutate($actor, $uuid, 'assign', $data, function (SupportTicket $ticket) use ($actor, $assignee): void {
            $before = $ticket->assignee_id;
            $ticket->assignee_id = $assignee?->id;
            $this->timeline->append($ticket, $actor, 'support.ticket.assigned', null, ['previous_assignee_id' => $before, 'assignee_id' => $assignee?->id, 'assignee_name' => $assignee?->name]);
        });
    }

    public function transition(SupportActor $actor, string $uuid, array $input): array
    {
        $data = Validator::make($input, [
            'client_operation_uuid' => 'required|uuid',
            'status' => ['required', Rule::in(['IN_PROGRESS', 'WAITING', 'RESOLVED', 'CLOSED', 'CANCELLED'])],
            'resolution' => 'required_if:status,RESOLVED|nullable|string|max:5000',
        ])->validate();
        $action = match ($data['status']) {
            'RESOLVED' => 'resolve', 'CLOSED', 'CANCELLED' => 'manage', default => 'transition'
        };

        return $this->mutate($actor, $uuid, $action, $data, function (SupportTicket $ticket) use ($actor, $data): void {
            abort_unless(in_array($data['status'], self::TRANSITIONS[$ticket->status] ?? [], true), 409, 'El estado actual ya no permite ese cambio.');
            $before = $ticket->status;
            $ticket->status = $data['status'];
            $kind = 'support.ticket.status_changed';
            if ($ticket->status === 'RESOLVED') {
                $ticket->resolved_at = now('UTC');
                $ticket->resolution = trim($data['resolution']);
                $kind = 'support.ticket.resolved';
            } elseif ($ticket->status === 'CLOSED') {
                $ticket->closed_at = now('UTC');
                $kind = 'support.ticket.closed';
            } elseif ($ticket->status === 'IN_PROGRESS') {
                $this->firstResponse($ticket, $actor);
            }
            $this->timeline->append($ticket, $actor, $kind, null, ['previous_status' => $before, 'status' => $ticket->status]);
        });
    }

    private function mutate(SupportActor $actor, string $uuid, string $action, array $data, \Closure $change): array
    {
        $ticket = $this->access->ticket($actor, $uuid);
        $this->access->authorize($actor, $action, $ticket);

        return $this->operations->run($actor, $data['client_operation_uuid'], ['action' => $action, 'ticket' => $uuid, 'data' => $data], function () use ($actor, $action, $ticket, $change): array {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $action, $locked);
            abort_if($locked->terminal(), 409, 'El reporte está cerrado y conserva su historial.');
            $change($locked);
            $this->sla->evaluate($locked);
            $locked->updated_at = now('UTC');
            $locked->save();

            return ['ticket' => $this->presenter->ticket($locked)];
        });
    }

    private function firstResponse(SupportTicket $ticket, SupportActor $actor): void
    {
        $support = ($actor->kind === 'user' && $actor->model->hasPermission('support', 'resolve'))
            || ($actor->kind === 'integration' && in_array('support.tickets.resolve', $actor->abilities, true));
        if ($support && $ticket->first_response_at === null) {
            $ticket->first_response_at = now('UTC');
        }
    }
}
