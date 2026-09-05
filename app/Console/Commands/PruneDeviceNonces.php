<?php

namespace App\Console\Commands;

use App\Models\DeviceNonce;
use Illuminate\Console\Command;

class PruneDeviceNonces extends Command
{
    protected $signature = 'device-nonces:prune {--batch=}';

    protected $description = 'Delete expired device HMAC nonces in bounded batches.';

    public function handle(): int
    {
        $batchSize = max(100, (int) ($this->option('batch') ?: config('vending.device.nonce_prune_batch_size', 5000)));
        $ids = DeviceNonce::query()
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');
        $deleted = $ids->isEmpty()
            ? 0
            : DeviceNonce::query()->whereIn('id', $ids)->delete();

        $this->info("Expired device nonces pruned: {$deleted}");

        return self::SUCCESS;
    }
}
