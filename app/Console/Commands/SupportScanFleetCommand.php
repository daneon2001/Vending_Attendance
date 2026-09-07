<?php

namespace App\Console\Commands;

use App\Services\Support\SupportAutomationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SupportScanFleetCommand extends Command
{
    protected $signature = 'support:scan-fleet {--batch= : Maximum devices, bounded by configuration} {--machine= : Restrict to one internal machine ID}';

    protected $description = 'Evaluate one bounded fleet page against an explicitly enabled support policy.';

    public function handle(SupportAutomationService $automation): int
    {
        foreach (['batch', 'machine'] as $option) {
            $value = $this->option($option);
            if ($value !== null && (! ctype_digit((string) $value) || (int) $value < 1)) {
                $this->error('La opción --'.$option.' debe ser un entero positivo.');

                return self::INVALID;
            }
        }
        try {
            $result = $automation->scan(
                $this->option('batch') === null ? null : (int) $this->option('batch'),
                $this->option('machine') === null ? null : (int) $this->option('machine'),
            );
        } catch (ValidationException) {
            $this->error('SUPPORT_POLICY_INVALID: revise la versión y el alcance de la política.');

            return self::FAILURE;
        }
        $this->info($result['enabled'] ? 'SUPPORT_SCAN_COMPLETE' : 'SUPPORT_AUTOMATION_DISABLED');
        $this->line('Devices: '.$result['processed'].'; observations: '.$result['observations']);

        return self::SUCCESS;
    }
}
