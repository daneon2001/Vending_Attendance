<?php

use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('employees:prune-imports')->hourly()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'dev:templates:tombstone {vendor_template_id} {--vendor=digitalpersona} {--employee_id=} {--deleted_at=}',
    function (): int {
        if (app()->environment('production')) {
            $this->error('Comando bloqueado en production.');

            return self::FAILURE;
        }

        $vendorTemplateId = trim((string) $this->argument('vendor_template_id'));
        $vendor = trim((string) $this->option('vendor')) ?: 'digitalpersona';
        $employeeId = $this->option('employee_id');
        $deletedAtRaw = $this->option('deleted_at');

        if ($vendorTemplateId === '') {
            $this->error('vendor_template_id es requerido.');

            return self::FAILURE;
        }

        try {
            $deletedAt = $deletedAtRaw ? Carbon::parse((string) $deletedAtRaw) : now();
        } catch (\Throwable $e) {
            $this->error('deleted_at invalido. Usa formato ISO o YmdHis.');

            return self::FAILURE;
        }

        $id = DB::table('employee_template_deletions')->insertGetId([
            'vendor' => $vendor,
            'vendor_template_id' => $vendorTemplateId,
            'employee_id' => $employeeId !== null && $employeeId !== '' ? (int) $employeeId : null,
            'deleted_at' => $deletedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Tombstone creado. id={$id}, vendor={$vendor}, vendor_template_id={$vendorTemplateId}, deleted_at={$deletedAt->toIso8601String()}");

        return self::SUCCESS;
    }
)->purpose('Inserta tombstone DEV para templates');

Artisan::command(
    'dev:templates:deletions {--since=}',
    function (): int {
        if (app()->environment('production')) {
            $this->error('Comando bloqueado en production.');

            return self::FAILURE;
        }

        $query = DB::table('employee_template_deletions')
            ->select(['id', 'vendor', 'vendor_template_id', 'employee_id', 'deleted_at'])
            ->orderByDesc('deleted_at')
            ->limit(50);

        $sinceRaw = trim((string) $this->option('since'));
        if ($sinceRaw !== '') {
            try {
                $since = preg_match('/^\d{14}$/', $sinceRaw) === 1
                    ? Carbon::createFromFormat('YmdHis', $sinceRaw)
                    : Carbon::parse($sinceRaw);
                $query->where('deleted_at', '>', $since);
            } catch (\Throwable $e) {
                $this->error('since invalido. Usa formato ISO o YmdHis.');

                return self::FAILURE;
            }
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn('Sin tombstones.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'vendor', 'vendor_template_id', 'employee_id', 'deleted_at'],
            $rows->map(fn ($row) => (array) $row)->all()
        );

        return self::SUCCESS;
    }
)->purpose('Lista tombstones DEV de templates');

Schedule::command('audit:cleanup --optimize')
    ->dailyAt((string) config('audit.cleanup.schedule_time', '03:00'))
    ->withoutOverlapping();

Schedule::command('device-nonces:prune')
    ->everyMinute()
    ->withoutOverlapping();

if (config('sybi.vending.sync_enabled', false)) {
    $sybiIntervalMinutes = max(1, (int) config('sybi.vending.sync_interval_minutes', 60));

    Schedule::command('sybi:sync-vending')
        ->everyMinute()
        ->when(fn (): bool => intdiv(now()->timestamp, 60) % $sybiIntervalMinutes === 0)
        ->withoutOverlapping();
}
