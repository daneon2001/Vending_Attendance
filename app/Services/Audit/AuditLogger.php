<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class AuditLogger
{
    /** @var array<string, bool>|null */
    private static ?array $columns = null;

    public static function log(string $event, ?Model $auditable = null, ?string $description = null, array $metadata = []): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $user = auth()->user();
        $request = request();

        $cleanMetadata = Arr::wrap($metadata);
        if ($cleanMetadata === [[]]) {
            $cleanMetadata = [];
        }

        $action = self::resolveAction($event, $cleanMetadata);
        $entity = self::resolveEntity($auditable, $cleanMetadata);
        $entityId = self::resolveEntityId($auditable, $cleanMetadata);
        $reason = self::resolveReason($description, $cleanMetadata);

        $oldValues = self::resolveOldValues($cleanMetadata);
        $newValues = self::resolveNewValues($cleanMetadata);

        $requestId = (string) ($request?->attributes->get('request_id') ?? $request?->header('X-Request-Id') ?? '');
        $correlationId = (string) ($request?->attributes->get('correlation_id') ?? $request?->header('X-Correlation-Id') ?? $requestId);

        $deviceId = Arr::get($cleanMetadata, 'device_id');
        $onpremDevice = $request?->attributes->get('onprem_device');
        if ($deviceId === null) {
            $deviceId = $onpremDevice?->id;
        }

        $actorType = $user ? 'user' : ($onpremDevice ? 'device' : 'system');
        $actorIdentifier = $user?->email ?? ($user?->name ?? ($onpremDevice?->device_serial ?? 'system'));

        $nowUtc = now('UTC');
        $localTz = config('app.timezone', 'UTC');
        $nowLocal = $nowUtc->copy()->setTimezone($localTz);

        $payload = [
            // Legacy fields
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'description' => $description,
            'metadata' => empty($cleanMetadata) ? null : $cleanMetadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),

            // Forensic fields
            'actor_user_id' => $user?->id,
            'actor_type' => $actorType,
            'actor_identifier' => $actorIdentifier,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'request_id' => $requestId !== '' ? $requestId : null,
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
            'device_id' => $deviceId !== null ? (int) $deviceId : null,
            'occurred_at_utc' => $nowUtc,
            'occurred_at_local' => $nowLocal,
            'timezone' => $localTz,
        ];

        $safePayload = self::filterPayloadByExistingColumns($payload);

        try {
            AuditLog::query()->create($safePayload);
        } catch (\Throwable $exception) {
            // Defensive no-op: audit should not break business flow.
        }
    }

    private static function resolveAction(string $event, array $metadata): string
    {
        $fromMetadata = Arr::get($metadata, 'action');
        if (is_string($fromMetadata) && trim($fromMetadata) !== '') {
            return strtolower(trim($fromMetadata));
        }

        $tail = strtolower(trim((string) str($event)->afterLast('.')));

        return match ($tail) {
            'created', 'stored' => 'create',
            'updated', 'status_changed', 'annulled', 'manual_adjustment' => 'update',
            'deleted', 'destroyed' => 'delete',
            'login', 'authenticate', 'authenticated' => 'login',
            'logout' => 'logout',
            'export', 'exported' => 'export',
            default => $tail !== '' ? $tail : 'event',
        };
    }

    private static function resolveEntity(?Model $auditable, array $metadata): ?string
    {
        $fromMetadata = Arr::get($metadata, 'entity');
        if (is_string($fromMetadata) && trim($fromMetadata) !== '') {
            return trim($fromMetadata);
        }

        return $auditable?->getTable();
    }

    private static function resolveEntityId(?Model $auditable, array $metadata): ?string
    {
        $fromMetadata = Arr::get($metadata, 'entity_id');
        if ($fromMetadata !== null && $fromMetadata !== '') {
            return (string) $fromMetadata;
        }

        return $auditable?->getKey() !== null ? (string) $auditable->getKey() : null;
    }

    private static function resolveReason(?string $description, array $metadata): ?string
    {
        $reason = Arr::get($metadata, 'reason');
        if (is_string($reason) && trim($reason) !== '') {
            return trim($reason);
        }

        return $description;
    }

    private static function resolveOldValues(array $metadata): ?array
    {
        $candidate = Arr::get($metadata, 'old_values', Arr::get($metadata, 'before'));

        return is_array($candidate) && $candidate !== [] ? $candidate : null;
    }

    private static function resolveNewValues(array $metadata): ?array
    {
        $candidate = Arr::get($metadata, 'new_values', Arr::get($metadata, 'after'));

        return is_array($candidate) && $candidate !== [] ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function filterPayloadByExistingColumns(array $payload): array
    {
        if (self::$columns === null) {
            try {
                self::$columns = array_fill_keys(Schema::getColumnListing('audit_logs'), true);
            } catch (\Throwable $exception) {
                self::$columns = [];
            }
        }

        $safe = [];
        foreach ($payload as $key => $value) {
            if (isset(self::$columns[$key])) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
