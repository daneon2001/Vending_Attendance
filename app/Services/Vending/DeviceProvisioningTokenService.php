<?php

namespace App\Services\Vending;

use App\Models\DeviceProvisioningToken;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeviceProvisioningTokenService
{
    /**
     * @return array{token:DeviceProvisioningToken,plain_token:string}
     */
    public function create(
        VendingMachine $machine,
        ?int $actorId = null,
        DateTimeInterface|string|null $expiresAt = null,
    ): array {
        $plainToken = bin2hex(random_bytes(32));
        $expiration = $expiresAt
            ? Carbon::parse($expiresAt)
            : now()->addMinutes((int) config('vending.device.provisioning_token_ttl_minutes', 30));

        $token = new DeviceProvisioningToken([
            'vending_machine_id' => $machine->id,
            'expires_at' => $expiration,
            'created_by' => $actorId,
        ]);
        $token->forceFill([
            'token_hash' => hash('sha256', $plainToken),
        ]);
        $machine->provisioningTokens()->save($token);

        AuditLogger::log('device.provisioning_token.created', $token, 'Device provisioning token created.', [
            'after' => [
                'token_uuid' => $token->uuid,
                'vending_machine_id' => $machine->id,
                'expires_at' => $expiration->toIso8601String(),
            ],
        ]);

        return ['token' => $token, 'plain_token' => $plainToken];
    }

    public function revoke(DeviceProvisioningToken $token, ?int $actorId = null, ?string $reason = null): DeviceProvisioningToken
    {
        return DB::transaction(function () use ($token, $actorId, $reason): DeviceProvisioningToken {
            $locked = DeviceProvisioningToken::query()->whereKey($token->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->used_at === null && $locked->revoked_at === null) {
                $locked->forceFill([
                    'revoked_at' => now(),
                    'revoked_by' => $actorId,
                    'revocation_reason' => $reason,
                ])->save();

                AuditLogger::log('device.provisioning_token.revoked', $locked, 'Device provisioning token revoked.', [
                    'after' => [
                        'token_uuid' => $locked->uuid,
                        'revoked_at' => $locked->revoked_at?->toIso8601String(),
                        'reason' => $reason,
                    ],
                ]);
            }

            return $locked->fresh();
        });
    }
}
