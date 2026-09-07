<?php

namespace App\Services\Support;

use App\Models\SupportOperation;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SupportOperations
{
    public function __construct(private readonly SupportAccess $access) {}

    public function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function run(SupportActor $actor, string $operationUuid, array $fingerprint, Closure $operation): array
    {
        Validator::make(['client_operation_uuid' => $operationUuid], ['client_operation_uuid' => 'required|uuid'])->validate();
        $hash = $this->fingerprint($fingerprint);

        return DB::transaction(function () use ($actor, $operationUuid, $hash, $operation): array {
            // Authentication is a snapshot. Revalidate the live Device binding before
            // either returning a prior receipt or taking any operation/aggregate lock.
            if ($actor->kind === 'device') {
                $this->access->lockDeviceContext($actor, (int) $actor->model?->vending_machine_id);
            }
            // Duplicate-key no-op takes an exclusive MySQL row lock directly. INSERT IGNORE
            // can retain shared duplicate locks that deadlock when contenders upgrade below.
            // Only the identical normalized key is assigned; receipt content is immutable here.
            DB::table('support_operations')->upsert([
                'principal_key' => $actor->key(), 'operation_uuid' => strtolower($operationUuid),
                'request_hash' => $hash, 'created_at' => now('UTC'),
            ], ['principal_key', 'operation_uuid'], ['operation_uuid']);
            $receipt = SupportOperation::query()->where('principal_key', $actor->key())
                ->where('operation_uuid', strtolower($operationUuid))->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'Esta operación ya fue utilizada con otros datos.');
            if ($receipt->result !== null) {
                return $receipt->result;
            }
            $result = $operation();
            $receipt->update(['result' => $result]);

            return $result;
        }, 3);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }
}
