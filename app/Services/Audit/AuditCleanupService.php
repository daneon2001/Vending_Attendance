<?php

namespace App\Services\Audit;

use App\Models\AuditCleanupRun;
use App\Models\AuditCleanupSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditCleanupService
{
    public function __construct(
        private readonly AuditEventClassifier $classifier
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $settings = $this->currentSettings();
        $rowCount = $this->auditLogsTableExists() ? (int) DB::table('audit_logs')->count() : 0;
        $sizeBytes = $this->tableSizeBytes('audit_logs');
        $topEvents = $this->topEvents();
        $latestRun = $this->cleanupRunsTableExists()
            ? AuditCleanupRun::query()->latest('created_at')->first()
            : null;
        $deletedRecords = $this->cleanupRunsTableExists()
            ? (int) AuditCleanupRun::query()->where('status', 'completed')->sum('deleted_records')
            : 0;
        $freedBytes = $this->cleanupRunsTableExists()
            ? (int) AuditCleanupRun::query()->where('status', 'completed')->sum('estimated_bytes_freed')
            : 0;

        return [
            'settings' => $settings,
            'stats' => [
                'row_count' => $rowCount,
                'size_bytes' => $sizeBytes,
                'size_human' => $this->humanBytes($sizeBytes),
                'avg_row_bytes' => $rowCount > 0 && $sizeBytes !== null ? (int) ceil($sizeBytes / $rowCount) : 0,
                'deleted_records_total' => $deletedRecords,
                'deleted_records_total_human' => number_format($deletedRecords),
                'freed_bytes_total' => $freedBytes,
                'freed_bytes_total_human' => $this->humanBytes($freedBytes),
                'latest_cleanup' => $latestRun ? [
                    'status' => $latestRun->status,
                    'trigger_source' => $latestRun->trigger_source,
                    'deleted_records' => (int) $latestRun->deleted_records,
                    'estimated_bytes_freed' => (int) $latestRun->estimated_bytes_freed,
                    'estimated_bytes_freed_human' => $this->humanBytes((int) $latestRun->estimated_bytes_freed),
                    'optimized' => (bool) $latestRun->optimized,
                    'duration_ms' => (int) $latestRun->duration_ms,
                    'started_at' => optional($latestRun->started_at)?->toIso8601String(),
                    'finished_at' => optional($latestRun->finished_at)?->toIso8601String(),
                    'created_at' => optional($latestRun->created_at)?->toIso8601String(),
                ] : null,
            ],
            'top_events' => $topEvents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function currentSettings(): array
    {
        if (! $this->cleanupSettingsTableExists()) {
            return AuditCleanupSetting::defaults();
        }

        $settings = AuditCleanupSetting::singleton();

        return [
            'critical_retention_days' => (int) $settings->critical_retention_days,
            'important_retention_days' => (int) $settings->important_retention_days,
            'noise_retention_days' => (int) $settings->noise_retention_days,
            'batch_size' => (int) $settings->batch_size,
            'heartbeat_log_interval_minutes' => (int) $settings->heartbeat_log_interval_minutes,
            'optimize_min_deleted_mb' => (int) $settings->optimize_min_deleted_mb,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateSettings(array $input, ?User $user = null): array
    {
        if (! $this->cleanupSettingsTableExists()) {
            return $this->resolveSettingsFromPayload(['settings' => $input]);
        }

        $settings = AuditCleanupSetting::singleton();
        $settings->fill([
            'critical_retention_days' => (int) ($input['critical_retention_days'] ?? $settings->critical_retention_days),
            'important_retention_days' => (int) ($input['important_retention_days'] ?? $settings->important_retention_days),
            'noise_retention_days' => (int) ($input['noise_retention_days'] ?? $settings->noise_retention_days),
            'batch_size' => (int) ($input['batch_size'] ?? $settings->batch_size),
            'heartbeat_log_interval_minutes' => (int) ($input['heartbeat_log_interval_minutes'] ?? $settings->heartbeat_log_interval_minutes),
            'optimize_min_deleted_mb' => (int) ($input['optimize_min_deleted_mb'] ?? $settings->optimize_min_deleted_mb),
            'updated_by_user_id' => $user?->id,
        ]);
        $settings->save();

        return $this->currentSettings();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload): array
    {
        $settings = $this->resolveSettingsFromPayload($payload);
        $filters = $this->normalizeFilters($payload);

        return $this->estimate($settings, $filters);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, ?User $user = null, string $triggerSource = 'manual'): array
    {
        $settings = $this->resolveSettingsFromPayload($payload);
        $filters = $this->normalizeFilters($payload);
        $estimate = $this->estimate($settings, $filters);
        $startedAt = now();

        $run = $this->cleanupRunsTableExists()
            ? AuditCleanupRun::query()->create([
                'initiated_by_user_id' => $user?->id,
                'trigger_source' => $triggerSource,
                'status' => 'running',
                'mode' => 'cleanup',
                'settings_snapshot' => $settings,
                'filters_snapshot' => $filters,
                'summary' => $estimate,
                'started_at' => $startedAt,
            ])
            : null;

        if (($estimate['records_to_delete'] ?? 0) <= 0) {
            if ($run) {
                $run->forceFill([
                    'status' => 'completed',
                    'deleted_records' => 0,
                    'estimated_bytes_freed' => 0,
                    'optimized' => false,
                    'optimize_statement_ran' => false,
                    'finished_at' => now(),
                    'duration_ms' => $startedAt->diffInMilliseconds(now()),
                    'summary' => $estimate,
                ])->save();
            }

            return array_merge($estimate, [
                'deleted_records' => 0,
                'estimated_bytes_freed' => 0,
                'estimated_bytes_freed_human' => $this->humanBytes(0),
                'optimized' => false,
            ]);
        }

        $deletedRecords = 0;
        $estimatedBytesFreed = 0;
        $optimizeRan = false;
        $avgRowBytes = (int) ($estimate['avg_row_bytes'] ?? 0);
        $targetFreeBytes = (int) ($filters['target_free_bytes'] ?? 0);
        $batchSize = max(100, (int) ($settings['batch_size'] ?? 5000));

        try {
            do {
                $ids = $this->candidateIdsQuery($settings, $filters)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($ids === []) {
                    break;
                }

                $deleted = DB::table('audit_logs')->whereIn('id', $ids)->delete();
                $deletedRecords += (int) $deleted;
                $estimatedBytesFreed += $avgRowBytes > 0 ? ((int) $deleted * $avgRowBytes) : 0;

                if ($targetFreeBytes > 0 && $estimatedBytesFreed >= $targetFreeBytes) {
                    break;
                }
            } while (true);

            $shouldOptimize = $this->shouldOptimizeTable($filters, $settings, $estimatedBytesFreed);
            if ($shouldOptimize) {
                $optimizeRan = $this->optimizeTable('audit_logs');
            }

            $result = array_merge($estimate, [
                'deleted_records' => $deletedRecords,
                'estimated_bytes_freed' => $estimatedBytesFreed,
                'estimated_bytes_freed_human' => $this->humanBytes($estimatedBytesFreed),
                'optimized' => $optimizeRan,
            ]);

            if ($run) {
                $run->forceFill([
                    'status' => 'completed',
                    'deleted_records' => $deletedRecords,
                    'estimated_bytes_freed' => $estimatedBytesFreed,
                    'optimized' => $optimizeRan,
                    'optimize_statement_ran' => $optimizeRan,
                    'finished_at' => now(),
                    'duration_ms' => $startedAt->diffInMilliseconds(now()),
                    'summary' => $result,
                ])->save();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($run) {
                $run->forceFill([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'duration_ms' => $startedAt->diffInMilliseconds(now()),
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, int>  $settings
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function estimate(array $settings, array $filters): array
    {
        if (! $this->auditLogsTableExists()) {
            return [
                'mode' => $filters['mode'],
                'records_to_delete' => 0,
                'estimated_bytes' => 0,
                'estimated_bytes_human' => $this->humanBytes(0),
                'first_record_at' => null,
                'last_record_at' => null,
                'avg_row_bytes' => 0,
                'matched_categories' => [],
            ];
        }

        $base = $this->candidateIdsQuery($settings, $filters);
        $rowCount = (int) DB::table('audit_logs')->count();
        $tableBytes = $this->tableSizeBytes('audit_logs');
        $avgRowBytes = $rowCount > 0 && $tableBytes !== null ? (int) ceil($tableBytes / $rowCount) : 0;
        $count = (clone $base)->count();
        $firstRecordAt = (clone $base)->min('created_at');
        $lastRecordAt = (clone $base)->max('created_at');

        return [
            'mode' => $filters['mode'],
            'records_to_delete' => (int) $count,
            'estimated_bytes' => $avgRowBytes > 0 ? ((int) $count * $avgRowBytes) : 0,
            'estimated_bytes_human' => $this->humanBytes($avgRowBytes > 0 ? ((int) $count * $avgRowBytes) : 0),
            'first_record_at' => $firstRecordAt ? Carbon::parse((string) $firstRecordAt)->toIso8601String() : null,
            'last_record_at' => $lastRecordAt ? Carbon::parse((string) $lastRecordAt)->toIso8601String() : null,
            'avg_row_bytes' => $avgRowBytes,
            'matched_categories' => $filters['matched_categories'],
            'target_free_bytes' => (int) ($filters['target_free_bytes'] ?? 0),
            'target_free_human' => $this->humanBytes((int) ($filters['target_free_bytes'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, int>  $settings
     * @param  array<string, mixed>  $filters
     */
    private function candidateIdsQuery(array $settings, array $filters): Builder
    {
        $columns = array_values(array_intersect(
            ['id', 'event', 'action', 'entity', 'description', 'reason', 'created_at'],
            $this->classifier->availableAuditColumns()
        ));
        $query = DB::table('audit_logs')->select($columns === [] ? ['id'] : $columns);

        if ($filters['mode'] === 'retention') {
            $criticalCutoff = now('UTC')->subDays($settings['critical_retention_days']);
            $importantCutoff = now('UTC')->subDays($settings['important_retention_days']);
            $noiseCutoff = now('UTC')->subDays($settings['noise_retention_days']);

            $query->where(function (Builder $outer) use ($criticalCutoff, $importantCutoff, $noiseCutoff): void {
                $outer->orWhere(function (Builder $critical) use ($criticalCutoff): void {
                    $this->classifier->applyCategoryConstraint($critical, AuditEventClassifier::CATEGORY_CRITICAL);
                    $critical->where('created_at', '<', $criticalCutoff);
                })->orWhere(function (Builder $important) use ($importantCutoff): void {
                    $this->classifier->applyCategoryConstraint($important, AuditEventClassifier::CATEGORY_IMPORTANT);
                    $important->where('created_at', '<', $importantCutoff);
                })->orWhere(function (Builder $noise) use ($noiseCutoff): void {
                    $this->classifier->applyCategoryConstraint($noise, AuditEventClassifier::CATEGORY_NOISE);
                    $noise->where('created_at', '<', $noiseCutoff);
                });
            });

            return $query;
        }

        $beforeDateUtc = $filters['before_date_utc'];
        $hasExplicitSelector = (bool) $filters['has_explicit_selector'];

        if ($beforeDateUtc) {
            $query->where('created_at', '<', $beforeDateUtc);
        }

        if (! $hasExplicitSelector && $beforeDateUtc) {
            return $query;
        }

        $query->where(function (Builder $outer) use ($filters): void {
            if ($filters['delete_heartbeats']) {
                $outer->orWhere(function (Builder $heartbeat): void {
                    $this->classifier->applySelectorConstraint($heartbeat, 'heartbeat');
                });
            }

            if ($filters['delete_sync']) {
                $outer->orWhere(function (Builder $sync): void {
                    $this->classifier->applySelectorConstraint($sync, 'sync');
                });
            }

            if ($filters['delete_old_logins']) {
                $outer->orWhere(function (Builder $login): void {
                    $this->classifier->applySelectorConstraint($login, 'login');
                });
            }

            if ($filters['delete_noise']) {
                $outer->orWhere(function (Builder $noise): void {
                    $this->classifier->applyCategoryConstraint($noise, AuditEventClassifier::CATEGORY_NOISE);
                });
            }

            if ($filters['delete_except_critical']) {
                $outer->orWhere(function (Builder $notCritical): void {
                    $notCritical->where(function (Builder $important): void {
                        $this->classifier->applyCategoryConstraint($important, AuditEventClassifier::CATEGORY_IMPORTANT);
                    })->orWhere(function (Builder $noise): void {
                        $this->classifier->applyCategoryConstraint($noise, AuditEventClassifier::CATEGORY_NOISE);
                    });
                });
            }
        });

        return $query;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function resolveSettingsFromPayload(array $payload): array
    {
        $current = $this->currentSettings();
        $incoming = Arr::wrap($payload['settings'] ?? []);

        return [
            'critical_retention_days' => max(1, (int) ($incoming['critical_retention_days'] ?? $current['critical_retention_days'])),
            'important_retention_days' => max(1, (int) ($incoming['important_retention_days'] ?? $current['important_retention_days'])),
            'noise_retention_days' => max(1, (int) ($incoming['noise_retention_days'] ?? $current['noise_retention_days'])),
            'batch_size' => max(100, (int) ($incoming['batch_size'] ?? $current['batch_size'])),
            'heartbeat_log_interval_minutes' => max(1, (int) ($incoming['heartbeat_log_interval_minutes'] ?? $current['heartbeat_log_interval_minutes'])),
            'optimize_min_deleted_mb' => max(1, (int) ($incoming['optimize_min_deleted_mb'] ?? $current['optimize_min_deleted_mb'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $payload): array
    {
        $selection = Arr::wrap($payload['selection'] ?? []);
        $targetFreeMb = (int) ($selection['target_free_mb'] ?? 0);
        $beforeDate = isset($selection['before_date']) && $selection['before_date'] !== ''
            ? Carbon::parse((string) $selection['before_date'], config('app.timezone', 'UTC'))->startOfDay()->setTimezone('UTC')
            : null;
        $flags = [
            'delete_heartbeats' => (bool) ($selection['delete_heartbeats'] ?? false),
            'delete_sync' => (bool) ($selection['delete_sync'] ?? false),
            'delete_old_logins' => (bool) ($selection['delete_old_logins'] ?? false),
            'delete_noise' => (bool) ($selection['delete_noise'] ?? false),
            'delete_except_critical' => (bool) ($selection['delete_except_critical'] ?? false),
        ];
        $hasExplicitSelector = collect($flags)->contains(true);
        $mode = $hasExplicitSelector || $beforeDate ? 'selection' : 'retention';
        $matchedCategories = collect($flags)
            ->filter(fn (bool $enabled) => $enabled)
            ->keys()
            ->values()
            ->all();

        return array_merge($flags, [
            'mode' => $mode,
            'before_date_utc' => $beforeDate,
            'target_free_bytes' => max(0, $targetFreeMb) * 1024 * 1024,
            'optimize' => (bool) ($selection['optimize'] ?? false),
            'simulate' => (bool) ($selection['simulate'] ?? false),
            'has_explicit_selector' => $hasExplicitSelector,
            'matched_categories' => $matchedCategories,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topEvents(): array
    {
        if (! $this->auditLogsTableExists()) {
            return [];
        }

        return DB::table('audit_logs')
            ->selectRaw('event, COUNT(*) as total, MIN(created_at) as first_seen_at, MAX(created_at) as last_seen_at')
            ->groupBy('event')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(function ($row): array {
                $event = (string) ($row->event ?? '');
                $category = $this->classifier->classify([
                    'event' => $event,
                    'action' => null,
                    'entity' => null,
                    'description' => null,
                    'reason' => null,
                ]);

                return [
                    'event' => $event,
                    'category' => $category,
                    'total' => (int) ($row->total ?? 0),
                    'first_seen_at' => $row->first_seen_at ? Carbon::parse((string) $row->first_seen_at)->toIso8601String() : null,
                    'last_seen_at' => $row->last_seen_at ? Carbon::parse((string) $row->last_seen_at)->toIso8601String() : null,
                ];
            })
            ->values()
            ->all();
    }

    private function auditLogsTableExists(): bool
    {
        return Schema::hasTable('audit_logs');
    }

    private function cleanupSettingsTableExists(): bool
    {
        return Schema::hasTable('audit_cleanup_settings');
    }

    private function cleanupRunsTableExists(): bool
    {
        return Schema::hasTable('audit_cleanup_runs');
    }

    private function tableSizeBytes(string $table): ?int
    {
        if (! $this->auditLogsTableExists() || DB::getDriverName() !== 'mysql') {
            return null;
        }

        $database = DB::getDatabaseName();
        $result = DB::table('information_schema.TABLES')
            ->selectRaw('COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) as total_bytes')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->first();

        return $result ? (int) ($result->total_bytes ?? 0) : null;
    }

    private function humanBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $index = 0;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return number_format($size, $index === 0 ? 0 : 2).' '.$units[$index];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, int>  $settings
     */
    private function shouldOptimizeTable(array $filters, array $settings, int $estimatedBytesFreed): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        if (! (bool) ($filters['optimize'] ?? false)) {
            return false;
        }

        $thresholdBytes = max(1, (int) ($settings['optimize_min_deleted_mb'] ?? 512)) * 1024 * 1024;

        return $estimatedBytesFreed >= $thresholdBytes;
    }

    private function optimizeTable(string $table): bool
    {
        try {
            DB::statement('OPTIMIZE TABLE '.$table);

            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }
}
