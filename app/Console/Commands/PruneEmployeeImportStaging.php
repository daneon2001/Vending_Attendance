<?php

namespace App\Console\Commands;

use App\Enums\Employees\EmployeeImportRunStatus;
use App\Models\EmployeeImportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneEmployeeImportStaging extends Command
{
    protected $signature = 'employees:prune-imports';

    protected $description = 'Delete expired employee staging rows, retaining compact run metadata.';

    public function handle(): int
    {
        $removed = 0;
        EmployeeImportRun::where('expires_at', '<', now())->orderBy('id')->chunkById(100, function ($runs) use (&$removed): void {
            foreach ($runs as $run) {
                $removed += DB::transaction(function () use ($run): int {
                    $run = EmployeeImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                    $count = $run->rows()->delete();
                    if ($run->status === EmployeeImportRunStatus::PREVIEW) {
                        $run->update(['status' => EmployeeImportRunStatus::EXPIRED]);
                    }

                    return $count;
                });
            }
        });
        $this->info('Expired staging rows removed: '.$removed);

        return self::SUCCESS;
    }
}
