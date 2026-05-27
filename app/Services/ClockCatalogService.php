<?php

namespace App\Services;

use App\Models\Clock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ClockCatalogService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        $query = Clock::query()->with(['company', 'location']);

        $this->applyCatalogFilters($query, $filters, true);

        return $query
            ->orderBy('clock_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{online:int,warnings:int,offline:int,total:int}
     */
    public function summarize(array $filters): array
    {
        $query = Clock::query();

        $this->applyCatalogFilters($query, $filters, false);

        return [
            'online' => (int) (clone $query)->monitoringOnline()->count(),
            'warnings' => (int) (clone $query)->monitoringWarning()->count(),
            'offline' => (int) (clone $query)->monitoringOffline()->count(),
            'total' => (int) (clone $query)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyCatalogFilters(Builder $query, array $filters, bool $includeMonitoringStatus = true): void
    {
        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $subQuery) use ($search): void {
                $subQuery
                    ->where('clock_name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('last_seen_ip', 'like', "%{$search}%")
                    ->orWhereHas('company', fn (Builder $companyQuery) => $companyQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('location', fn (Builder $locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"));
            });
        }

        if (isset($filters['company_id']) && $filters['company_id'] !== '' && is_numeric((string) $filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (isset($filters['location_id']) && $filters['location_id'] !== '') {
            $locationFilter = (string) $filters['location_id'];

            if ($locationFilter === 'unassigned') {
                $query->whereNull('location_id');
            } elseif (is_numeric($locationFilter)) {
                $query->where('location_id', (int) $locationFilter);
            }
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = (string) $filters['status'];

            if (in_array($status, ['0', '1'], true)) {
                $query->where('status', (int) $status);
            }
        }

        if ($includeMonitoringStatus && isset($filters['monitoring_status']) && trim((string) $filters['monitoring_status']) !== '') {
            $this->applyMonitoringStatusFilter($query, trim((string) $filters['monitoring_status']));
        }

        if (isset($filters['program_status']) && trim((string) $filters['program_status']) !== '') {
            $query->where('program_status', trim((string) $filters['program_status']));
        }
    }

    protected function applyMonitoringStatusFilter(Builder $query, string $monitoringStatus): void
    {
        match ($monitoringStatus) {
            'online' => $query->monitoringOnline(),
            'warning' => $query->monitoringWarning(),
            'offline' => $query->monitoringOffline(),
            default => null,
        };
    }
}
