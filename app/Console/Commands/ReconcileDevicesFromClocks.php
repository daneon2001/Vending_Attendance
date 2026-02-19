<?php

namespace App\Console\Commands;

use App\Models\Clock;
use App\Models\Device;
use App\Services\OnPrem\DeviceRegistryService;
use Illuminate\Console\Command;

class ReconcileDevicesFromClocks extends Command
{
    protected $signature = 'onprem:reconcile-devices
                            {--dry-run : Solo muestra cambios sin escribir en BD}
                            {--secret-for-missing= : Shared secret para devices nuevos o sin secreto}';

    protected $description = 'Alinea tabla devices con clocks usando serial_number como llave de integracion.';

    public function handle(DeviceRegistryService $registry): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $secretForMissing = trim((string) $this->option('secret-for-missing'));

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $withoutSecret = 0;

        Clock::query()
            ->orderBy('id')
            ->chunkById(200, function ($clocks) use ($registry, $dryRun, $secretForMissing, &$created, &$updated, &$skipped, &$withoutSecret): void {
                foreach ($clocks as $clock) {
                    $serial = trim((string) $clock->serial_number);
                    if ($serial === '') {
                        $skipped++;
                        $this->warn("clock_id={$clock->id} omitido: serial_number vacio.");
                        continue;
                    }

                    $before = Device::query()->where('device_serial', $serial)->first();
                    $incomingSecret = null;
                    if ($secretForMissing !== '' && trim((string) ($before?->shared_secret ?? '')) === '') {
                        $incomingSecret = $secretForMissing;
                    }

                    if ($dryRun) {
                        $action = $before ? 'UPDATE' : 'CREATE';
                        $this->line("[DRY-RUN] {$action} serial={$serial} clock_id={$clock->id} unit_id={$clock->location_id} company_id={$clock->company_id}");
                        continue;
                    }

                    $device = $registry->syncFromClock($clock, $incomingSecret);
                    if ($before) {
                        $updated++;
                    } else {
                        $created++;
                    }

                    if (trim((string) $device->shared_secret) === '') {
                        $withoutSecret++;
                        $this->warn("serial={$serial} quedo sin shared_secret.");
                    }
                }
            });

        $this->newLine();
        $this->info('Reconciliacion finalizada.');
        $this->line("created={$created}");
        $this->line("updated={$updated}");
        $this->line("skipped={$skipped}");
        $this->line("without_secret={$withoutSecret}");

        if ($dryRun) {
            $this->comment('Modo dry-run: no se escribieron cambios.');
        }

        return self::SUCCESS;
    }
}
