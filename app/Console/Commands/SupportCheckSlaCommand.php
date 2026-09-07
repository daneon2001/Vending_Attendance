<?php

namespace App\Console\Commands;

use App\Services\Support\SupportSlaService;
use Illuminate\Console\Command;

class SupportCheckSlaCommand extends Command
{
    protected $signature = 'support:check-sla {--batch=100}';

    protected $description = 'Evaluate one bounded page of tickets with explicitly snapshotted SLA deadlines.';

    public function handle(SupportSlaService $sla): int
    {
        if (! ctype_digit((string) $this->option('batch')) || (int) $this->option('batch') < 1) {
            $this->error('El límite debe ser un entero positivo.');

            return self::INVALID;
        }
        $result = $sla->scan((int) $this->option('batch'));
        $this->info('SUPPORT_SLA_CHECKED: '.$result['processed'].'; events: '.$result['events']);

        return self::SUCCESS;
    }
}
