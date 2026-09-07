<?php

namespace App\Services\Support;

use App\Models\SupportPolicyVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportPolicyService
{
    public const VERIFICATION_CHECKS = [
        'DEVICE_ACTIVE', 'HEARTBEAT', 'APP_VERSION', 'CONFIGURATION_MANIFEST', 'EMPLOYEE_MANIFEST',
        'GPS_PERMISSION', 'GPS_AVAILABILITY', 'CAMERA_PERMISSION', 'CAMERA_AVAILABILITY',
        'NETWORK', 'API_REACHABILITY', 'LOCAL_CONFIGURATION', 'LOCAL_EMPLOYEES', 'LOCAL_OUTBOX',
    ];

    public function __construct(private readonly SupportAccess $access, private readonly SupportOperations $operations) {}

    public function publish(SupportActor $actor, array $input): array
    {
        $actor = $this->configurator($actor);
        $data = Validator::make(['policy' => $input], [
            'policy' => ['required', 'array:client_operation_uuid,label,is_demo,active,valid_from,valid_until,payload'],
            'policy.client_operation_uuid' => ['required', 'uuid'],
            'policy.label' => ['required', 'string', 'max:160'],
            'policy.is_demo' => ['required', 'boolean'], 'policy.active' => ['required', 'boolean'],
            'policy.valid_from' => ['required', 'date'],
            'policy.valid_until' => ['nullable', 'date', 'after:policy.valid_from'],
            'policy.payload' => ['required', 'array:sla,automation'],
            'policy.payload.sla' => ['sometimes', 'array'],
            'policy.payload.automation' => ['sometimes', 'array'],
        ])->validate()['policy'];
        if (! is_bool($data['is_demo']) || ! is_bool($data['active'])) {
            throw ValidationException::withMessages(['policy' => 'Los indicadores deben ser booleanos.']);
        }
        $payload = [];
        if (isset($data['payload']['sla'])) {
            $payload['sla'] = self::validatedSla($data['payload']['sla']);
        }
        if (isset($data['payload']['automation'])) {
            $payload['automation'] = self::validatedAutomation($data['payload']['automation'], $data['is_demo']);
        }
        $data['payload'] = $payload;
        $data['valid_from'] = Carbon::parse($data['valid_from'])->utc()->toIso8601String();
        $data['valid_until'] = isset($data['valid_until']) ? Carbon::parse($data['valid_until'])->utc()->toIso8601String() : null;

        return $this->operations->run($actor, $data['client_operation_uuid'], ['action' => 'policy.publish', 'data' => $data], function () use ($actor, $data): array {
            $cursor = DB::table('support_runtime_cursors')->where('key', 'policy_version')->lockForUpdate()->firstOrFail();
            $version = (int) $cursor->value + 1;
            DB::table('support_runtime_cursors')->where('key', 'policy_version')->update(['value' => $version]);
            $policy = SupportPolicyVersion::query()->create([
                'version' => $version, 'label' => trim($data['label']), 'is_demo' => $data['is_demo'],
                'active' => $data['active'], 'valid_from' => $data['valid_from'], 'valid_until' => $data['valid_until'] ?? null,
                'payload' => $data['payload'], 'created_by' => $actor->id,
            ]);
            AuditLogger::log('support.policy.published', $policy, 'Versión de política de soporte publicada.', [
                'version' => $version, 'is_demo' => $policy->is_demo, 'active' => $policy->active,
                'support_actor_kind' => $actor->kind, 'support_actor_id' => $actor->id,
            ]);

            return $this->present($policy);
        });
    }

    public function deactivate(SupportActor $actor, int $policyId, string $operationUuid): array
    {
        $actor = $this->configurator($actor);

        return $this->operations->run($actor, $operationUuid, ['action' => 'policy.deactivate', 'id' => $policyId], function () use ($actor, $policyId): array {
            $policy = SupportPolicyVersion::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            if ($policy->active) {
                $policy->update(['active' => false]);
                AuditLogger::log('support.policy.deactivated', $policy, 'Política de soporte desactivada.', [
                    'version' => $policy->version, 'support_actor_kind' => $actor->kind, 'support_actor_id' => $actor->id,
                ]);
            }

            return $this->present($policy);
        });
    }

    public function effective(?CarbonInterface $at = null): ?SupportPolicyVersion
    {
        $at ??= now('UTC');

        return SupportPolicyVersion::query()->where('active', true)->where('valid_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $at))
            ->orderByDesc('version')->first();
    }

    public static function validatedSla(array $input): array
    {
        $sla = Validator::make(['sla' => $input], [
            'sla' => ['required', 'array:enabled,response_minutes,resolution_minutes,warning_minutes'],
            'sla.enabled' => ['required', 'boolean'],
            'sla.response_minutes' => ['required_if:sla.enabled,true', 'integer', 'min:1', 'max:43200'],
            'sla.resolution_minutes' => ['required_if:sla.enabled,true', 'integer', 'min:1', 'max:525600', 'gte:sla.response_minutes'],
            'sla.warning_minutes' => ['required_if:sla.enabled,true', 'integer', 'min:1', 'lte:sla.response_minutes'],
        ])->validate()['sla'];
        if (! is_bool($sla['enabled'])) {
            throw ValidationException::withMessages(['sla.enabled' => 'El indicador debe ser booleano.']);
        }

        return $sla['enabled'] ? [
            'enabled' => true, 'response_minutes' => (int) $sla['response_minutes'],
            'resolution_minutes' => (int) $sla['resolution_minutes'], 'warning_minutes' => (int) $sla['warning_minutes'],
        ] : ['enabled' => false];
    }

    public static function validatedAutomation(array $input, bool $isDemo): array
    {
        $input = $input === [] ? ['enabled' => false, 'rules' => []] : $input;
        $validated = Validator::make(['automation' => $input], [
            'automation' => ['required', 'array:enabled,rules'],
            'automation.enabled' => ['required', 'boolean'],
            'automation.rules' => ['required_if:automation.enabled,true', 'array', 'max:7'],
            'automation.rules.*' => ['array:key,enabled,source,persistence_seconds,cooldown_seconds,category,severity,priority,machine_ids,check_codes'],
            'automation.rules.*.key' => ['required', 'distinct', Rule::in([
                'MANIFEST_ERROR', 'OUTBOX_HIGH', 'STORAGE_LOW', 'CLOCK_DRIFT_HIGH', 'UNSUPPORTED_VERSION',
                'DEVICE_OFFLINE_PERSISTENT', 'VERIFICATION_CRITICAL',
            ])],
            'automation.rules.*.enabled' => ['required', 'boolean'],
            'automation.rules.*.source' => ['required', Rule::in(['FLEET', 'VERIFICATION'])],
            'automation.rules.*.persistence_seconds' => ['required', 'integer', 'min:0', 'max:604800'],
            'automation.rules.*.cooldown_seconds' => ['required', 'integer', 'min:60', 'max:2592000'],
            'automation.rules.*.category' => ['required', Rule::in(array_keys(config('support.categories', [])))],
            'automation.rules.*.severity' => ['required', Rule::in(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])],
            'automation.rules.*.priority' => ['required', Rule::in(['LOW', 'NORMAL', 'HIGH', 'URGENT'])],
            'automation.rules.*.machine_ids' => ['present', 'array', 'max:1000'],
            'automation.rules.*.machine_ids.*' => ['integer', 'min:1'],
            'automation.rules.*.check_codes' => ['sometimes', 'array', 'min:1', 'max:15'],
            'automation.rules.*.check_codes.*' => [Rule::in(self::VERIFICATION_CHECKS)],
        ])->validate()['automation'];
        if (! is_bool($validated['enabled'])) {
            throw ValidationException::withMessages(['automation.enabled' => 'El indicador debe ser booleano.']);
        }
        $validated['rules'] ??= [];
        foreach ($validated['rules'] as &$rule) {
            if (! is_bool($rule['enabled']) || ($isDemo && $rule['machine_ids'] === [])
                || (($rule['key'] === 'VERIFICATION_CRITICAL') !== ($rule['source'] === 'VERIFICATION'))
                || ($rule['source'] === 'VERIFICATION' && empty($rule['check_codes']))) {
                throw ValidationException::withMessages(['policy' => 'La política requiere alcance y checks explícitos.']);
            }
            $rule['machine_ids'] = array_values(array_unique(array_map('intval', $rule['machine_ids'])));
            $rule['persistence_seconds'] = (int) $rule['persistence_seconds'];
            $rule['cooldown_seconds'] = (int) $rule['cooldown_seconds'];
        }
        unset($rule);

        return $validated;
    }

    private function configurator(SupportActor $actor): SupportActor
    {
        abort_unless($actor->kind === 'user', 403);
        $user = User::query()->with('roles.permissions')->find($actor->id);
        abort_unless($user, 403);
        $fresh = SupportActor::user($user);
        $this->access->authorize($fresh, 'configure');

        return $fresh;
    }

    private function present(SupportPolicyVersion $policy): array
    {
        return [
            'id' => $policy->id, 'version' => (int) $policy->version, 'label' => $policy->label,
            'is_demo' => $policy->is_demo, 'active' => $policy->active,
            'valid_from' => $policy->valid_from->toIso8601String(), 'valid_until' => $policy->valid_until?->toIso8601String(),
            'payload' => $policy->payload,
        ];
    }
}
