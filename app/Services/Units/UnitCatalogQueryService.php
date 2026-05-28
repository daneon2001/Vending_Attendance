<?php

namespace App\Services\Units;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

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
            ->with('company:id,name')
            ->withCount('clocks');

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
            });
        }
    }
}
