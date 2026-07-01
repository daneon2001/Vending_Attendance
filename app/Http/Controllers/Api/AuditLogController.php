<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\Audit\AuditCleanupService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $perPage = min(max($request->integer('per_page', 15), 1), 25);
        $page = max($request->integer('page', 1), 1);
        [$from, $to, $range] = $this->resolveRange($request);

        Log::info('audit.logs.index.start', [
            'range' => $range,
            'page' => $page,
            'per_page' => $perPage,
            'user_id' => $request->input('user_id'),
            'event' => $request->input('event'),
            'q' => (string) $request->string('q')->trim(),
        ]);

        try {
            $query = AuditLog::query()
                ->select([
                    'id',
                    'user_id',
                    'actor_user_id',
                    'actor_type',
                    'actor_identifier',
                    'user_name',
                    'user_email',
                    'event',
                    'action',
                    'entity',
                    'entity_id',
                    'auditable_type',
                    'auditable_id',
                    'description',
                    'reason',
                    'ip_address',
                    'created_at',
                ]);

            if ($from && $to) {
                $query->whereBetween('created_at', [$from, $to]);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->integer('user_id'));
            }

            if ($request->filled('event')) {
                $query->where('event', (string) $request->string('event')->trim());
            }

            if ($search = (string) $request->string('q')->trim()) {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('description', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%")
                        ->orWhere('user_name', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%");
                });
            }

            $query->orderByDesc('created_at')->orderByDesc('id');

            Log::info('audit.logs.index.query', [
                'sql' => $query->toSql(),
                'bindings' => $this->normalizeBindings($query->getBindings()),
            ]);

            $logs = $query
                ->simplePaginate($perPage, ['*'], 'page', $page)
                ->withQueryString();

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('audit.logs.index.done', [
                'range' => $range,
                'page' => $page,
                'per_page' => $perPage,
                'duration_ms' => $durationMs,
                'returned' => $logs->count(),
                'has_more_pages' => $logs->hasMorePages(),
            ]);

            return response()->json([
                'data' => AuditLogResource::collection($logs->getCollection())->resolve(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->hasMorePages() ? $logs->currentPage() + 1 : $logs->currentPage(),
                    'per_page' => $logs->perPage(),
                    'total' => null,
                    'from' => $logs->firstItem() ?? 0,
                    'to' => $logs->lastItem() ?? 0,
                    'has_more_pages' => $logs->hasMorePages(),
                    'next_page_url' => $logs->nextPageUrl(),
                    'prev_page_url' => $logs->previousPageUrl(),
                    'pagination_type' => 'simple',
                    'duration_ms' => $durationMs,
                ],
            ]);
        } catch (\Throwable $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::error('audit.logs.index.failed', [
                'duration_ms' => $durationMs,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No fue posible cargar la bitacora de auditoria.',
                'error' => 'AUDIT_LOGS_LOAD_FAILED',
                'duration_ms' => $durationMs,
            ], 500);
        }
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        try {
            $auditLog->load('user:id,name,email');

            $timezone = $this->auditTimezone();
            $createdAtUtc = $this->normalizeStoredDateTime($auditLog->created_at);

            return response()->json([
                'data' => [
                    'id' => $auditLog->id,
                    'event' => $auditLog->event,
                    'action' => $auditLog->action,
                    'entity' => $auditLog->entity ?? $auditLog->auditable_type,
                    'entity_id' => $auditLog->entity_id ?? $auditLog->auditable_id,
                    'description' => $auditLog->description,
                    'reason' => $auditLog->reason,
                    'user' => [
                        'id' => $auditLog->actor_user_id ?? $auditLog->user_id,
                        'name' => $auditLog->user_name ?? optional($auditLog->user)->name,
                        'email' => $auditLog->user_email ?? optional($auditLog->user)->email,
                        'type' => $auditLog->actor_type ?? 'user',
                        'identifier' => $auditLog->actor_identifier,
                    ],
                    'auditable_type' => $auditLog->auditable_type,
                    'auditable_id' => $auditLog->auditable_id,
                    'old_values' => $auditLog->old_values,
                    'new_values' => $auditLog->new_values,
                    'metadata' => $auditLog->metadata,
                    'ip_address' => $auditLog->ip_address,
                    'user_agent' => $auditLog->user_agent,
                    'request_id' => $auditLog->request_id,
                    'correlation_id' => $auditLog->correlation_id,
                    'device_id' => $auditLog->device_id,
                    'occurred_at_utc' => optional($auditLog->occurred_at_utc)->toISOString(),
                    'occurred_at_local' => optional($auditLog->occurred_at_local)->format('d/m/Y H:i:s'),
                    'timezone' => $auditLog->timezone,
                    'created_at' => optional($createdAtUtc)->toISOString(),
                    'created_at_local' => optional($createdAtUtc)
                        ? $createdAtUtc->copy()->setTimezone($timezone)->format('d/m/Y H:i:s')
                        : null,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('audit.logs.show.failed', [
                'audit_log_id' => $auditLog->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No fue posible cargar el detalle de auditoria.',
                'error' => 'AUDIT_LOG_SHOW_FAILED',
            ], 500);
        }
    }

    public function purge(Request $request, AuditCleanupService $cleanupService): JsonResponse
    {
        $minKeepDays = max(1, (int) config('audit.cleanup.min_keep_days', 90));

        $validated = $request->validate([
            'mode' => ['required', 'in:before_date,keep_last_days,delete_by_range'],
            'before_date' => ['nullable', 'date_format:Y-m-d'],
            'keep_days' => ['nullable', 'integer', 'min:'.$minKeepDays, 'max:3650'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'dry_run' => ['nullable', 'boolean'],
            'optimize' => ['nullable', 'boolean'],
        ]);

        $mode = (string) $validated['mode'];

        if ($mode === 'before_date' && empty($validated['before_date'])) {
            return response()->json([
                'message' => 'before_date es obligatorio para el modo before_date.',
            ], 422);
        }

        if ($mode === 'keep_last_days' && empty($validated['keep_days'])) {
            return response()->json([
                'message' => 'keep_days es obligatorio para el modo keep_last_days.',
            ], 422);
        }

        if ($mode === 'delete_by_range' && (empty($validated['date_from']) || empty($validated['date_to']))) {
            return response()->json([
                'message' => 'date_from y date_to son obligatorios para el modo delete_by_range.',
            ], 422);
        }

        if (
            $mode === 'delete_by_range'
            && ! empty($validated['date_from'])
            && ! empty($validated['date_to'])
            && (string) $validated['date_from'] > (string) $validated['date_to']
        ) {
            return response()->json([
                'message' => 'date_from no puede ser mayor que date_to.',
            ], 422);
        }

        try {
            if (empty($validated['dry_run'])) {
                $this->extendPurgeExecutionWindow();
            }

            $result = ! empty($validated['dry_run'])
                ? $cleanupService->previewPurge($validated)
                : $cleanupService->executePurge($validated, $request->user(), 'manual');

            return response()->json([
                'message' => ! empty($validated['dry_run'])
                    ? 'Simulacion de limpieza completada.'
                    : 'Limpieza de bitacora completada.',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            Log::error('audit.logs.purge.failed', [
                'mode' => $mode,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No fue posible ejecutar la limpieza de auditoria.',
                'error' => 'AUDIT_LOG_PURGE_FAILED',
            ], 500);
        }
    }

    protected function resolveRange(Request $request): array
    {
        $range = (string) $request->string('range', 'today');
        $timezone = $this->auditTimezone();
        $now = now($timezone);

        if ($range === 'custom' && $request->filled(['from', 'to'])) {
            $from = $this->parseLocalDate($request->input('from'), $timezone);
            $to = $this->parseLocalDate($request->input('to'), $timezone);

            if ($from && $to) {
                if ($from->gt($to)) {
                    [$from, $to] = [$to, $from];
                }

                return [
                    $from->copy()->startOfDay()->setTimezone('UTC'),
                    $to->copy()->endOfDay()->setTimezone('UTC'),
                    'custom',
                ];
            }
        }

        if ($range === 'all') {
            return [null, null, 'all'];
        }

        if ($range === '7d' || $range === 'week') {
            $start = $now->copy()->subDays(6)->startOfDay();
            $end = $now->copy()->endOfDay();
            $resolvedRange = '7d';
        } elseif ($range === '30d' || $range === 'month') {
            $start = $now->copy()->subDays(29)->startOfDay();
            $end = $now->copy()->endOfDay();
            $resolvedRange = '30d';
        } else {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
            $resolvedRange = 'today';
        }

        return [
            $start->setTimezone('UTC'),
            $end->setTimezone('UTC'),
            $resolvedRange,
        ];
    }

    protected function parseLocalDate(?string $value, string $timezone): ?Carbon
    {
        if (! $value) {
            return null;
        }

        $formats = ['d/m/Y', 'Y-m-d'];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value, $timezone);
            } catch (\Throwable $th) {
                continue;
            }
        }

        return null;
    }

    private function auditTimezone(): string
    {
        return (string) config('operations.timezone', config('app.timezone', 'America/Mexico_City'));
    }

    private function extendPurgeExecutionWindow(): void
    {
        $seconds = max(60, (int) config('audit.cleanup.purge_request_timeout_seconds', 3600));

        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }

    private function storageTimezone(): string
    {
        return (string) config('operations.storage_timezone', 'UTC');
    }

    private function normalizeStoredDateTime(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return Carbon::parse($value->format('Y-m-d H:i:s'), $this->storageTimezone());
        }

        return Carbon::parse((string) $value, $this->storageTimezone());
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    private function normalizeBindings(array $bindings): array
    {
        return array_map(function ($binding) {
            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s');
            }

            if (is_string($binding) && mb_strlen($binding) > 150) {
                return mb_substr($binding, 0, 150).'...';
            }

            return $binding;
        }, $bindings);
    }
}
