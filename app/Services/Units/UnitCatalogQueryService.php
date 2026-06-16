<?php

namespace App\Services\Units;

use App\Models\Clock;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnitCatalogQueryService
{
    /**
     * Shared visibility query for unit catalog summary and list.
     */
    public function buildVisibleQuery(): Builder
    {
        return Unit::query();
    }

    public function buildFilteredListQuery(array $filters): Builder
    {
        $query = $this->buildVisibleQuery()
            ->with('company:id,name');

        $this->applyCatalogSelects($query);

        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * @return array{total_units:int,active_units:int,inactive_units:int}
     */
    public function buildSummary(): array
    {
        $summary = $this->buildVisibleQuery()
            ->selectRaw('COUNT(*) as total_units')
            ->selectRaw('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_units')
            ->selectRaw('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive_units')
            ->first();

        return [
            'total_units' => (int) ($summary?->total_units ?? 0),
            'active_units' => (int) ($summary?->active_units ?? 0),
            'inactive_units' => (int) ($summary?->inactive_units ?? 0),
        ];
    }

    public function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");

                if (Schema::hasColumn('locations', 'fortia_location_id') && preg_match('/^\d+$/', $search) === 1) {
                    $searchQuery->orWhere('fortia_location_id', (int) $search);
                }
            });
        }
    }

    public function applyCatalogSelects(Builder $query): void
    {
        $query->select('locations.*')
            ->withCount('clocks')
            ->withCount([
                'clocks as active_clocks_count' => fn (Builder $clockQuery) => $clockQuery->where('status', 1),
            ]);

        if (Schema::hasColumn('clocks', 'last_heartbeat_at')) {
            $query->selectSub(
                Clock::query()
                    ->selectRaw('MAX(last_heartbeat_at)')
                    ->whereColumn('clocks.location_id', 'locations.id'),
                'last_heartbeat_at'
            );

            $query->withCount([
                'clocks as offline_clocks_count' => function (Builder $clockQuery): void {
                    $clockQuery->where('status', 1)
                        ->where(function (Builder $offlineQuery): void {
                            $offlineQuery->whereNull('last_heartbeat_at');

                            if (Schema::hasColumn('clocks', 'last_heartbeat_at')) {
                                $offlineQuery->orWhere('last_heartbeat_at', '<', Clock::heartbeatOnlineThreshold());
                            }
                        });
                },
            ]);
        } else {
            $query->selectRaw('NULL as last_heartbeat_at')
                ->selectRaw('0 as offline_clocks_count');
        }
    }
}
