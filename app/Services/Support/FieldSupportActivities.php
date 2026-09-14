<?php

namespace App\Services\Support;

use App\Models\MachineGeofence;
use App\Models\VendingSupportActivity;
use App\Services\FieldIdentity\DeviceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Online, signed adapter. Domain transitions and location policy remain unchanged. */
class FieldSupportActivities
{
    public function __construct(private readonly FieldSupportActivityAccess $access,
        private readonly DeviceIdentityService $identity, private readonly SupportOperations $operations) {}

    public function capability(): array
    {
        [$user, $employee] = $this->access->identity();
        $this->access->permission($user, 'view');

        return ['available' => true, 'device_uuid' => $this->access->device($user, $employee)->uuid];
    }

    public function challenge(array $input): array
    {
        abort_if(array_key_exists('file_base64', $input), 422);
        $operation = $this->operation($input);
        $capability = $this->capability();
        $this->authorize($operation);

        return $this->identity->challenge($capability['device_uuid'], 'ACTOR', $this->hash($operation));
    }

    public function execute(array $input): array
    {
        $operation = $this->operation($input);
        $bytes = app(SupportActivityContributions::class)->bytes($operation, $input['file_base64'] ?? null);
        $proof = Validator::make($input, ['challenge_uuid' => 'required|uuid', 'signature' => 'required|string|max:1024'])->validate();
        [$user, $employee] = $this->access->identity();
        $device = $this->access->device($user, $employee);
        $challenge = DB::table('field_device_challenges')->where('uuid', $proof['challenge_uuid'])
            ->where('employee_device_id', $device->id)->first();
        $message = $challenge ? json_decode(explode("\n", $challenge->message, 2)[1] ?? '', true) : null;
        abort_unless(is_array($message) && isset($message['field_support_operation_hash'])
            && hash_equals($this->hash($operation), $message['field_support_operation_hash']), 403);
        // Commit one-time proof consumption even if subsequent domain validation fails.
        $context = $this->identity->prove($proof['challenge_uuid'], $proof['signature'], 'ACTOR')['context'];

        return DB::transaction(function () use ($operation, $context, $device, $bytes): array {
            [$user, $employee] = $this->access->identity();
            $current = $this->access->device($user, $employee);
            abort_unless((int) $current->id === $context->deviceId && (int) $user->id === $context->userId
                && (int) $employee->id === $context->employeeId && $current->key_fingerprint === $device->key_fingerprint, 403);
            $activity = $this->authorize($operation);
            if ($operation['action'] === 'list') {
                $page = $this->access->visible($user, $employee)->with(['vendingMachine', 'employee'])
                    ->orderByDesc('id')->simplePaginate(20, ['*'], 'page', $operation['page'] ?? 1);

                return ['data' => collect($page->items())->map(fn ($row) => $this->present($row))->all(),
                    'page' => $page->currentPage(), 'has_more' => $page->hasMorePages(),
                    'notifications' => app(SupportNotificationService::class)->feed(SupportActor::user($user), 20, true)];
            }
            if ($operation['action'] === 'notification_read') {
                app(SupportNotificationService::class)->markRead(SupportActor::user($user), $operation['notification_uuid'], true);

                return app(SupportNotificationService::class)->feed(SupportActor::user($user), 20, true);
            }
            if ($operation['action'] === 'detail') {
                return ['activity' => $this->present($activity, true)];
            }
            if (in_array($operation['action'], ['note', 'evidence'], true)) {
                $receipt = $this->operations->run(SupportActor::user($user), $operation['operation_uuid'], [
                    'intent' => 'field_support.contribution', 'employee_id' => $employee->id,
                    'device_id' => $current->id, 'operation' => $operation,
                ], fn () => app(SupportActivityContributions::class)->create($activity, $user, $current, $operation, $bytes));

                return ['confirmed' => true, 'operation_uuid' => $operation['operation_uuid'],
                    'receipt' => $receipt, 'activity' => $this->present($activity->refresh(), true)];
            }
            $receipt = $this->operations->run(SupportActor::user($user), $operation['operation_uuid'], [
                'intent' => 'field_support.activity', 'employee_id' => $employee->id,
                'device_id' => $current->id, 'operation' => $operation,
            ], function () use ($operation, $context, $current): array {
                $domain = app()->makeWith(SupportActivityService::class, ['access' => $this->access]);
                $row = $operation['action'] === 'start'
                    ? $domain->start($operation['activity_uuid'], $operation['location'])
                    : $domain->complete($operation['activity_uuid']);

                return ['activity_uuid' => $row->uuid, 'status' => $row->status->value,
                    'geofence_result' => $row->geofence_result?->value,
                    'actor_context' => (array) $context + ['device_uuid' => $current->uuid,
                        'key_version' => $current->key_version, 'key_fingerprint' => $current->key_fingerprint]];
            });

            // Historical receipt is retained privately; current state is always read from the server.
            return ['activity' => $this->present($activity->refresh(), true),
                'confirmed' => true, 'confirmed_status' => $receipt['status'],
                'geofence_result' => $receipt['geofence_result'], 'complete_location_policy' => 'START_ONLY_V1'];
        }, 3);
    }

    private function operation(array $input): array
    {
        abort_if(array_diff(array_keys($input), ['operation', 'challenge_uuid', 'signature', 'file_base64']), 422);

        $operation = Validator::make($input, [
            'operation' => 'required|array:action,activity_uuid,operation_uuid,location,page,body,captured_at,evidence,notification_uuid',
            'operation.action' => 'required|in:list,detail,start,complete,note,evidence,notification_read',
            'operation.activity_uuid' => 'required_unless:operation.action,list,notification_read|uuid',
            'operation.notification_uuid' => 'required_if:operation.action,notification_read|prohibited_unless:operation.action,notification_read|uuid',
            'operation.operation_uuid' => 'required_if:operation.action,start,complete,note,evidence|uuid',
            'operation.page' => 'sometimes|integer|min:1|max:10000',
            'operation.location' => 'required_if:operation.action,start|array:latitude,longitude,accuracy_m,captured_at',
            'operation.location.latitude' => 'required_with:operation.location|numeric|between:-90,90',
            'operation.location.longitude' => 'required_with:operation.location|numeric|between:-180,180',
            'operation.location.accuracy_m' => 'required_with:operation.location|numeric|between:0,100000',
            'operation.location.captured_at' => 'required_with:operation.location|date',
        ])->validate()['operation'];
        if ($operation['action'] === 'notification_read') {
            abort_if(array_diff(array_keys($operation), ['action', 'notification_uuid']), 422);

            return $operation;
        }
        if (in_array($operation['action'], ['note', 'evidence'], true)) {
            abort_if(isset($operation['location']) || isset($operation['page']), 422);

            return app(SupportActivityContributions::class)->validateOperation($operation);
        }
        abort_if(array_intersect(array_keys($operation), ['body', 'captured_at', 'evidence']), 422);

        return $operation;
    }

    private function hash(array $operation): string
    {
        return $this->operations->fingerprint(['protocol' => 'FIELD_SUPPORT_ONLINE_V1', 'operation' => $operation]);
    }

    private function authorize(array $operation): ?VendingSupportActivity
    {
        [$user, $employee] = $this->access->identity();
        $visible = $this->access->visible($user, $employee);
        if (in_array($operation['action'], ['list', 'notification_read'], true)) {
            return null;
        }
        $activity = $visible->where('uuid', $operation['activity_uuid'])->firstOrFail();
        $this->access->execute($activity, $user, $employee, $activity->vendingMachine);

        return $activity;
    }

    private function present(VendingSupportActivity $activity, bool $detail = false): array
    {
        $row = ['uuid' => $activity->uuid, 'title' => $activity->title, 'status' => $activity->status->value,
            'type_label' => $activity->activity_type->label(), 'employee' => $activity->employee->full_name,
            'machine' => $activity->vendingMachine->machine_code,
            'geofence_result' => $activity->geofence_result?->value,
            'started_at' => $activity->started_at?->toISOString(), 'completed_at' => $activity->completed_at?->toISOString()];
        if ($detail) {
            $zone = MachineGeofence::where('vending_machine_id', $activity->vending_machine_id)->effectiveAt()->first();
            $row += ['description' => $activity->description, 'complete_location_policy' => 'START_ONLY_V1',
                'geofence' => $zone ? ['uuid' => $zone->uuid, 'version' => $zone->version, 'type' => 'CIRCLE',
                    'latitude' => (float) $zone->center_latitude, 'longitude' => (float) $zone->center_longitude,
                    'radius_m' => $zone->radius_m, 'tolerance_m' => $zone->tolerance_m,
                    'minimum_acceptable_accuracy_m' => $zone->minimum_acceptable_accuracy_m] : null];
            $row += app(SupportActivityContributions::class)->present($activity);
        }

        return $row;
    }
}
