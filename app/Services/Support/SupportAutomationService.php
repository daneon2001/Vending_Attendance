<?php

namespace App\Services\Support;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\SupportCorrelation;
use App\Models\SupportPolicyVersion;
use App\Models\SupportTicket;
use App\Models\SupportVerification;
use App\Services\Vending\DeviceFleetHealthService;
use App\Services\Vending\MobileVersionPolicyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportAutomationService
{
    private const FLEET_REASONS = [
        'MANIFEST_ERROR' => 'MANIFEST_ERROR', 'OUTBOX_HIGH' => 'OUTBOX_PRESSURE',
        'STORAGE_LOW' => 'LOW_STORAGE', 'CLOCK_DRIFT_HIGH' => 'CLOCK_DRIFT',
        'UNSUPPORTED_VERSION' => 'APP_UNSUPPORTED',
    ];

    public function __construct(
        private readonly DeviceFleetHealthService $health,
        private readonly MobileVersionPolicyService $versions,
        private readonly SupportTicketService $tickets,
        private readonly SupportTimeline $timeline,
        private readonly SupportPolicyService $policies,
        private readonly SupportAccess $access,
    ) {}

    /** One bounded page; deliberately never called from the heartbeat. */
    public function scan(?int $batch = null, ?int $machineId = null): array
    {
        $result = ['enabled' => false, 'processed' => 0, 'observations' => 0, 'next_cursor' => null];
        $policy = $this->effectivePolicy();
        if (! $policy) {
            return $result;
        }
        $rules = $this->rules($policy, 'FLEET');
        if ($rules === []) {
            return $result;
        }
        $result['enabled'] = true;
        $limit = max(1, min($batch ?? (int) config('support.automation.batch_size', 100),
            (int) config('support.automation.max_batch_size', 250)));
        $cursorKey = $machineId === null ? 'fleet_device_id' : 'fleet_machine_'.$machineId;
        DB::table('support_runtime_cursors')->insertOrIgnore(['key' => $cursorKey, 'value' => 0]);
        $cursor = (int) DB::table('support_runtime_cursors')->where('key', $cursorKey)->value('value');
        $query = Device::query()->where('id', '>', $cursor)->whereNotNull('vending_machine_id')
            ->whereHas('vendingMachine', fn ($query) => $query->operational())
            ->when($machineId !== null, fn ($query) => $query->where('vending_machine_id', $machineId));
        if (collect($rules)->every(fn (array $rule): bool => $rule['machine_ids'] !== [])) {
            $query->whereIn('vending_machine_id', collect($rules)->pluck('machine_ids')->flatten()->unique()->all());
        }
        $devices = $query->with(['vendingMachine', 'manifestStates'])->orderBy('id')->limit($limit)->get();
        $policies = $this->versions->policyMap();
        $started = hrtime(true);
        $now = now();
        $lastId = $cursor;
        foreach ($devices as $device) {
            if ((hrtime(true) - $started) / 1_000_000_000 >= max(1, (int) config('support.automation.max_duration_seconds', 15))) {
                break;
            }
            $health = $this->health->evaluate($device, $now, $this->versions->policyFor($device, $policies), true);
            foreach ($rules as $rule) {
                if (! $this->inScope($device, $rule)) {
                    continue;
                }
                $this->observe($device, $rule, $this->fleetSignal($device, $health, $rule['key']), $policy, $now);
                $result['observations']++;
            }
            $result['processed']++;
            $lastId = (int) $device->id;
        }
        $next = $result['processed'] === $devices->count() && $devices->count() < $limit ? 0 : $lastId;
        // Concurrent scans cannot move a cursor backwards; correlation locks still protect each ticket.
        DB::table('support_runtime_cursors')->where('key', $cursorKey)->where('value', $cursor)->update(['value' => $next]);
        $result['next_cursor'] = (int) DB::table('support_runtime_cursors')->where('key', $cursorKey)->value('value');

        return $result;
    }

    public function evaluateVerification(SupportVerification $verification): ?SupportTicket
    {
        $policy = $this->effectivePolicy();
        if (! $policy) {
            return null;
        }
        $device = Device::query()->with(['vendingMachine', 'manifestStates'])->find($verification->device_id);
        if (! $device || (int) $device->vending_machine_id !== (int) $verification->vending_machine_id) {
            return null;
        }
        $checks = collect($verification->checks)->keyBy('code');
        foreach ($this->rules($policy, 'VERIFICATION') as $rule) {
            if (! $this->inScope($device, $rule)) {
                continue;
            }
            $selected = collect($rule['check_codes'])->map(fn (string $code) => $checks->get($code));
            $state = $selected->contains(fn ($check): bool => ($check['result'] ?? null) === 'FAIL') ? 'ACTIVE'
                : ($selected->every(fn ($check): bool => ($check['result'] ?? null) === 'PASS') ? 'RECOVERED' : 'UNKNOWN');
            $observed = $selected->filter()->max('observed_at');
            $at = $observed ? Carbon::parse($observed) : Carbon::instance($verification->completed_at);
            $ticket = $this->observe($device, $rule, $state, $policy, $at->min(now()), $verification);
            if ($ticket) {
                return $ticket;
            }
        }

        return null;
    }

    private function effectivePolicy(): ?SupportPolicyVersion
    {
        if (config('support.automation.enabled') !== true) {
            return null;
        }

        return $this->policies->effective();
    }

    private function rules(SupportPolicyVersion $policy, string $source): array
    {
        $automation = $policy->payload['automation'] ?? [];
        if (($automation['enabled'] ?? false) !== true) {
            return [];
        }
        $validated = SupportPolicyService::validatedAutomation($automation, $policy->is_demo)['rules'];

        return array_values(array_filter($validated, fn (array $rule): bool => $rule['enabled'] === true && $rule['source'] === $source));
    }

    private function inScope(Device $device, array $rule): bool
    {
        $machine = $device->vendingMachine;

        return $machine !== null && ! $this->access->reservedForFuturePhysicalTest($machine)
            && in_array($machine->status->value, ['ACTIVE', 'MAINTENANCE'], true)
            && ($rule['machine_ids'] === [] || in_array((int) $machine->id, array_map('intval', $rule['machine_ids']), true));
    }

    private function fleetSignal(Device $device, array $health, string $rule): string
    {
        if ($device->status !== DeviceStatus::ACTIVE) {
            return 'UNKNOWN';
        }
        if ($rule === 'DEVICE_OFFLINE_PERSISTENT') {
            return $health['status'] === 'OFFLINE' ? 'ACTIVE' : 'RECOVERED';
        }
        if (! in_array($health['status'], ['ONLINE', 'DEGRADED'], true)) {
            return 'UNKNOWN';
        }
        $missing = match ($rule) {
            'STORAGE_LOW' => $device->storage_free_mb === null,
            'OUTBOX_HIGH' => $device->pending_events_count === null,
            'CLOCK_DRIFT_HIGH' => $device->clock_drift_seconds === null,
            'UNSUPPORTED_VERSION' => $health['app_version']['status'] === 'UNKNOWN',
            default => false,
        };

        return $missing ? 'UNKNOWN' : (in_array(self::FLEET_REASONS[$rule], $health['reasons'], true) ? 'ACTIVE' : 'RECOVERED');
    }

    private function observe(Device $device, array $rule, string $state, SupportPolicyVersion $policy, Carbon $at, ?SupportVerification $verification = null): ?SupportTicket
    {
        $key = hash('sha256', $device->vendingMachine->uuid.'|'.$device->uuid.'|'.$rule['key']);

        return DB::transaction(function () use ($device, $rule, $state, $policy, $at, $verification, $key): ?SupportTicket {
            if ($state === 'ACTIVE') {
                // No-op duplicate-key update acquires X directly, avoiding shared-lock
                // upgrades under simultaneous scans; observation content is not overwritten.
                DB::table('support_correlations')->upsert([
                    'correlation_key' => $key, 'vending_machine_id' => $device->vending_machine_id,
                    'device_id' => $device->id, 'rule_key' => $rule['key'], 'policy_version_id' => $policy->id,
                    'signal_state' => 'UNKNOWN', 'first_observed_at' => $at, 'last_observed_at' => $at,
                    'created_at' => now(), 'updated_at' => now(),
                ], ['correlation_key'], ['correlation_key']);
            }
            $correlation = SupportCorrelation::query()->where('correlation_key', $key)->lockForUpdate()->first();
            if (! $correlation) {
                return null;
            }
            $ticket = $correlation->ticket_id ? SupportTicket::query()->lockForUpdate()->find($correlation->ticket_id) : null;
            if ($at->lt($correlation->last_observed_at)) {
                return $ticket;
            }
            $previous = $correlation->signal_state;
            $correlation->last_observed_at = $at;
            $correlation->signal_state = $state;
            if ($state === 'UNKNOWN') {
                $correlation->save();

                return $ticket;
            }
            if ($state === 'RECOVERED') {
                if ($correlation->last_active_at && ($previous === 'ACTIVE' || ($previous === 'UNKNOWN'
                    && (! $correlation->recovered_at || $correlation->last_active_at->gt($correlation->recovered_at))))) {
                    $correlation->recovered_at = $at;
                    if ($ticket && ! $ticket->terminal()) {
                        $this->timeline->append($ticket, SupportActor::system(), 'support.ticket.recovery', null, [
                            'rule_key' => $rule['key'], 'policy_version' => $policy->version,
                            'observed_at' => $at->toIso8601String(), 'verification_uuid' => $verification?->uuid,
                        ]);
                    }
                }
                $correlation->save();

                return $ticket;
            }
            if ($previous !== 'ACTIVE') {
                $correlation->first_observed_at = $at;
            }
            $correlation->last_active_at = $at;
            $correlation->save();
            if ($ticket && ! $ticket->terminal()) {
                return $ticket;
            }
            if ($ticket && (! $correlation->recovered_at || $correlation->recovered_at->lt($ticket->created_at))) {
                return $ticket;
            }
            if ($correlation->cooldown_until?->gt($at)
                || $correlation->first_observed_at->diffInSeconds($at) < (int) $rule['persistence_seconds']) {
                return $ticket;
            }
            $result = $this->tickets->create(SupportActor::system(), [
                'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $device->vending_machine_id,
                'device_id' => $device->id, 'source' => $verification ? 'DEVICE_VERIFICATION' : 'AUTOMATED_ALERT',
                'category' => $rule['category'], 'severity' => $rule['severity'], 'priority' => $rule['priority'],
                'title' => match ($rule['key']) {
                    'DEVICE_OFFLINE_PERSISTENT' => 'Dispositivo sin conexión',
                    'MANIFEST_ERROR' => 'Error de sincronización de configuración',
                    'OUTBOX_HIGH' => 'Registros pendientes de sincronización',
                    'STORAGE_LOW' => 'Espacio de almacenamiento reducido',
                    'CLOCK_DRIFT_HIGH' => 'Revisar fecha y hora del dispositivo',
                    'UNSUPPORTED_VERSION' => 'Actualizar la aplicación del dispositivo',
                    default => 'La verificación del equipo requiere atención',
                },
                'description' => 'Señal técnica persistente detectada por una política de soporte explícita.',
                'reported_at' => $at->toIso8601String(),
            ]);
            $ticket = SupportTicket::query()->where('uuid', $result['ticket']['uuid'])->firstOrFail();
            $this->timeline->append($ticket, SupportActor::system(), $verification ? 'support.ticket.verification_linked' : 'support.ticket.automation_linked', null, [
                'rule_key' => $rule['key'], 'policy_version' => $policy->version,
                'verification_uuid' => $verification?->uuid, 'observed_at' => $at->toIso8601String(),
            ]);
            $correlation->forceFill([
                'ticket_id' => $ticket->id, 'policy_version_id' => $policy->id,
                'recovered_at' => null,
                'cooldown_until' => $at->copy()->addSeconds((int) $rule['cooldown_seconds']),
            ])->save();

            return $ticket;
        }, 3);
    }
}
