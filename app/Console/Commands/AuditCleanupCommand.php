<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditCleanupService;
use Illuminate\Console\Command;

class AuditCleanupCommand extends Command
{
    protected $signature = 'audit:cleanup
        {--simulate : Solo calcula los registros elegibles}
        {--optimize : Ejecuta OPTIMIZE TABLE si se supera el umbral configurado}
        {--before-date= : Elimina registros anteriores a la fecha local YYYY-MM-DD}
        {--target-free-mb=0 : Detiene la limpieza al liberar aproximadamente esta cantidad en MB}';

    protected $description = 'Clean audit_logs by retention policy and safe batch deletes.';

    public function handle(AuditCleanupService $service): int
    {
        $payload = [
            'selection' => [
                'simulate' => (bool) $this->option('simulate'),
                'before_date' => $this->option('before-date') ?: null,
                'target_free_mb' => (int) $this->option('target-free-mb'),
                'optimize' => (bool) $this->option('optimize'),
            ],
        ];

        $result = (bool) $this->option('simulate')
            ? $service->preview($payload)
            : $service->execute($payload, null, 'scheduled');

        $this->info((bool) $this->option('simulate') ? 'Simulacion de limpieza completada.' : 'Limpieza de bitacora completada.');
        $this->line('mode: '.$result['mode']);
        $this->line('records: '.number_format((int) ($result['records_to_delete'] ?? $result['deleted_records'] ?? 0)));
        $this->line('estimated_bytes: '.($result['estimated_bytes_human'] ?? $result['estimated_bytes_freed_human'] ?? '0 B'));
        $this->line('first_record_at: '.($result['first_record_at'] ?? '--'));
        $this->line('last_record_at: '.($result['last_record_at'] ?? '--'));

        if (! (bool) $this->option('simulate')) {
            $this->line('deleted_records: '.number_format((int) ($result['deleted_records'] ?? 0)));
            $this->line('estimated_freed: '.($result['estimated_bytes_freed_human'] ?? '0 B'));
            $this->line('optimized: '.((bool) ($result['optimized'] ?? false) ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }
}
