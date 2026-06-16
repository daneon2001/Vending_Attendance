<?php

namespace App\Services\Units;

use App\Models\Clock;
use App\Models\Unit;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnitBulkMaintenanceService
{
    /**
     * @return array{criteria: array<int, string>, total_active_units: int, total_candidates: int, preview: array<int, array<string, mixed>>}
     */
    public function previewCandidates(int $limit = 50): array
    {
        $candidateQuery = $this->buildCandidateQuery();
        $preview = (clone $candidateQuery)
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Unit $unit) => $this->transformCandidate($unit))
            ->all();

        return [
            'criteria' => $this->criteriaDescriptions(),
            'total_active_units' => Unit::query()->where('status', 1)->count(),
            'total_candidates' => (clone $candidateQuery)->count(),
            'preview' => $preview,
        ];
    }

    /**
     * @return array{success: bool, dry_run: bool, deactivated: int, skipped: int, candidate_ids: array<int, int>, message: string}
     */
    public function bulkDeactivate(bool $dryRun = false): array
    {
        $candidates = $this->buildCandidateQuery()
            ->orderBy('id')
            ->get();

        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $count = count($candidateIds);

        if ($dryRun || $count === 0) {
            return [
                'success' => true,
                'dry_run' => $dryRun,
                'deactivated' => 0,
                'skipped' => 0,
                'candidate_ids' => $candidateIds,
                'message' => $dryRun
                    ? sprintf('%d unidades cumplen los criterios y serían desactivadas.', $count)
                    : 'No se encontraron unidades candidatas para desactivar.',
            ];
        }

        DB::transaction(function () use ($candidateIds): void {
            Unit::query()
                ->whereIn('id', $candidateIds)
                ->update([
                    'status' => 0,
                    'updated_at' => now(),
                ]);
        });

        AuditLogger::log(
            'units.bulk_deactivated',
            null,
            'Desactivación masiva de unidades sin código ni actividad',
            [
                'action' => 'bulk_deactivate',
                'entity' => 'locations',
                'reason' => 'inactive_units_without_code_or_activity',
                'new_values' => [
                    'criteria' => $this->criteriaDescriptions(),
                    'deactivated' => $count,
                    'unit_ids' => $candidateIds,
                ],
            ],
        );

        return [
            'success' => true,
            'dry_run' => false,
            'deactivated' => $count,
            'skipped' => 0,
            'candidate_ids' => $candidateIds,
            'message' => sprintf('%d unidades fueron desactivadas correctamente.', $count),
        ];
    }

    public function buildCandidateQuery(): Builder
    {
        $query = Unit::query()
            ->select('locations.*')
            ->with('company:id,name');

        $this->applyNoCodeCriteria($query);

        $query->where('status', 1)
            ->whereDoesntHave('clocks', function (Builder $clockQuery): void {
                $clockQuery->where('status', 1);
            });

        if (Schema::hasTable('attendance_logs') && Schema::hasColumn('attendance_logs', 'location_id')) {
            $query->whereNotExists(function ($attendanceQuery): void {
                $attendanceQuery
                    ->selectRaw('1')
                    ->from('attendance_logs')
                    ->whereColumn('attendance_logs.location_id', 'locations.id');
            });
        }

        $hasHeartbeatColumn = Schema::hasTable('clocks') && Schema::hasColumn('clocks', 'last_heartbeat_at');
        $hasMonitoringColumn = Schema::hasTable('clocks') && Schema::hasColumn('clocks', 'monitoring_status');

        if ($hasHeartbeatColumn || $hasMonitoringColumn) {
            $query->whereNotExists(function ($clockActivityQuery): void {
                $clockActivityQuery
                    ->selectRaw('1')
                    ->from('clocks')
                    ->whereColumn('clocks.location_id', 'locations.id')
                    ->where(function ($activityQuery): void {
                        if (Schema::hasColumn('clocks', 'last_heartbeat_at')) {
                            $activityQuery->whereNotNull('last_heartbeat_at');
                        }

                        if (Schema::hasColumn('clocks', 'monitoring_status')) {
                            $method = Schema::hasColumn('clocks', 'last_heartbeat_at') ? 'orWhereIn' : 'whereIn';
                            $activityQuery->{$method}('monitoring_status', ['online', 'warning']);
                        }
                    });
            });
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    public function criteriaDescriptions(): array
    {
        return [
            'Unidad activa.',
            'Sin código válido: code es null, vacío o 0.',
            'Sin relojes activos asociados.',
            'Sin registros históricos en attendance_logs asociados a la unidad.',
            'Sin heartbeat ni monitoreo operativo asociado en relojes vinculados.',
        ];
    }

    protected function applyNoCodeCriteria(Builder $query): void
    {
        $query->where(function (Builder $codeQuery): void {
            $codeQuery->whereNull('code')
                ->orWhereRaw("TRIM(COALESCE(code, '')) = ''")
                ->orWhere('code', '0');
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformCandidate(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'company_name' => $unit->company?->name,
            'name' => $unit->name,
            'code' => $unit->code,
            'fortia_location_id' => $unit->fortia_location_id ?? null,
            'city' => $unit->city,
            'status' => (int) $unit->status,
            'updated_at' => optional($unit->updated_at)?->setTimezone('America/Mexico_City')->format('Y-m-d H:i:s'),
        ];
    }
}
