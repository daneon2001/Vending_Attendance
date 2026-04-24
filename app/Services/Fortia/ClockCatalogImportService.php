<?php

namespace App\Services\Fortia;

use App\Models\Clock;
use App\Models\Company;
use App\Models\Location;
use App\Support\TabularDataReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClockCatalogImportService
{
    public function __construct(private readonly TabularDataReader $reader)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function import(?string $sourcePath = null, bool $dryRun = false, ?int $defaultCompanyId = null): array
    {
        $summary = [
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'source' => null,
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_invalid' => 0,
            'skipped_without_company' => 0,
            'suggested_locations' => [],
            'samples' => [
                'invalid_rows' => [],
                'without_company' => [],
            ],
        ];

        if (! Schema::hasTable('clocks') || ! Schema::hasTable('companies')) {
            return $summary + ['skipped_reason' => 'missing_tables'];
        }

        $sourcePath = $this->resolveSourcePath($sourcePath);
        if ($sourcePath === null || ! is_file($sourcePath)) {
            return $summary + ['skipped_reason' => 'source_not_found'];
        }

        $rows = $this->reader->readRows($sourcePath);
        if ($rows === []) {
            return $summary + ['source' => $sourcePath, 'skipped_reason' => 'source_empty'];
        }

        $summary['source'] = $sourcePath;

        $defaultCompanyId = $this->resolveDefaultCompanyId($defaultCompanyId);
        $summary['default_company_id'] = $defaultCompanyId;

        foreach ($rows as $row) {
            $summary['processed']++;

            $companyRaw = $this->rowValue($row, ['EMPRESA']);
            $serial = $this->rowValue($row, ['CLAVE SERIAL', 'CLAVE_SERIAL', 'SERIAL', 'SERIAL NUMBER', 'SERIAL_NUMBER']);
            $clockName = $this->rowValue($row, ['NOMBRE', 'NOMBRE RELOJ', 'CLOCK NAME', 'CLOCK_NAME']);

            if ($serial === null || $clockName === null) {
                $summary['skipped_invalid']++;
                $this->pushSample($summary['samples']['invalid_rows'], [
                    'empresa' => $companyRaw,
                    'clave_serial' => $serial,
                    'nombre' => $clockName,
                ]);
                continue;
            }

            $companyId = $this->resolveCompanyId($companyRaw, $defaultCompanyId);
            if ($companyId === null) {
                $summary['skipped_without_company']++;
                $this->pushSample($summary['samples']['without_company'], [
                    'empresa' => $companyRaw,
                    'clave_serial' => $serial,
                    'nombre' => $clockName,
                ]);
                continue;
            }

            $existingClocks = Clock::query()
                ->where('serial_number', $serial)
                ->orderBy('id')
                ->get();

            if ($existingClocks->count() > 1) {
                $summary['skipped_invalid']++;
                $this->pushSample($summary['samples']['invalid_rows'], [
                    'empresa' => $companyRaw,
                    'clave_serial' => $serial,
                    'nombre' => $clockName,
                    'reason' => 'duplicate_serial_in_clocks_table',
                ]);
                continue;
            }

            $existing = $existingClocks->first();
            $locationId = $existing?->location_id;
            if ($locationId === null) {
                $suggestion = $this->suggestLocation($clockName);
                if ($suggestion !== null) {
                    $this->pushSample($summary['suggested_locations'], [
                        'clave_serial' => $serial,
                        'clock_name' => $clockName,
                        'suggested_location_id' => $suggestion['id'],
                        'suggested_location_name' => $suggestion['name'],
                    ], 25);
                }
            }

            if ($dryRun) {
                if (! $existing) {
                    $summary['inserted']++;
                    continue;
                }

                if ((int) ($existing->company_id ?? 0) !== $companyId || (string) $existing->clock_name !== $clockName) {
                    $summary['updated']++;
                } else {
                    $summary['unchanged']++;
                }

                continue;
            }

            $clock = Clock::query()->updateOrCreate(
                ['serial_number' => $serial],
                [
                    'company_id' => $companyId,
                    'clock_name' => $clockName,
                ]
            );

            if ($clock->wasRecentlyCreated) {
                $summary['inserted']++;
                continue;
            }

            if ($clock->wasChanged()) {
                $summary['updated']++;
                continue;
            }

            $summary['unchanged']++;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(int $sample = 10): array
    {
        $sample = max(1, min($sample, 25));

        if (! Schema::hasTable('clocks')) {
            return [
                'counts' => [],
                'samples' => [],
                'skipped_reason' => 'missing_clocks_table',
            ];
        }

        $counts = [
            'total_clocks' => (int) Clock::query()->count(),
            'without_location' => (int) Clock::query()->whereNull('location_id')->count(),
            'company_not_found' => (int) DB::table('clocks as c')
                ->leftJoin('companies as co', 'co.id', '=', 'c.company_id')
                ->whereNotNull('c.company_id')
                ->whereNull('co.id')
                ->count(),
            'duplicates_by_serial' => (int) DB::table('clocks')
                ->select('serial_number')
                ->whereNotNull('serial_number')
                ->where('serial_number', '!=', '')
                ->groupBy('serial_number')
                ->havingRaw('COUNT(*) > 1')
                ->count(),
            'without_name' => (int) Clock::query()
                ->where(function ($query): void {
                    $query->whereNull('clock_name')
                        ->orWhere('clock_name', '');
                })
                ->count(),
            'inactive' => (int) Clock::query()->where('status', 0)->count(),
        ];

        $pending = Clock::query()
            ->with(['company:id,name'])
            ->whereNull('location_id')
            ->orderBy('id')
            ->limit($sample)
            ->get();

        $samples = [
            'pending_location_assignment' => $pending
                ->map(function (Clock $clock): array {
                    $suggestion = $this->suggestLocation($clock->clock_name);

                    return [
                        'id' => $clock->id,
                        'serial_number' => $clock->serial_number,
                        'clock_name' => $clock->clock_name,
                        'company_id' => $clock->company_id,
                        'company_name' => $clock->company?->name,
                        'suggested_location_id' => $suggestion['id'] ?? null,
                        'suggested_location_name' => $suggestion['name'] ?? null,
                    ];
                })
                ->values()
                ->all(),
        ];

        return [
            'counts' => $counts,
            'samples' => $samples,
        ];
    }

    private function resolveSourcePath(?string $sourcePath): ?string
    {
        if ($sourcePath !== null && trim($sourcePath) !== '') {
            $candidate = trim($sourcePath);
            if (! preg_match('/^[A-Za-z]:\\\\/', $candidate) && ! str_starts_with($candidate, '\\\\')) {
                $candidate = base_path($candidate);
            }

            return $candidate;
        }

        $candidates = [
            base_path('database/datos/relojes_fuente.csv'),
            base_path('database/datos/Relojes.csv'),
            base_path('database/datos/Relojes.xlsx'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveCompanyId(?string $empresa, ?int $defaultCompanyId = null): ?int
    {
        $empresa = $this->normalizeText($empresa);
        if ($empresa === null) {
            return null;
        }

        $query = Company::query();
        $company = $query
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($empresa)])
            ->first();
        if ($company) {
            return (int) $company->id;
        }

        if (Schema::hasColumn('companies', 'code')) {
            $company = Company::query()
                ->whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($empresa)])
                ->first();
            if ($company) {
                return (int) $company->id;
            }
        }

        if (is_numeric($empresa) && Schema::hasColumn('companies', 'fortia_company_id')) {
            $company = Company::query()
                ->where('fortia_company_id', (int) $empresa)
                ->first();
            if ($company) {
                return (int) $company->id;
            }
        }

        $likeMatches = Company::query()
            ->where('name', 'like', '%'.$empresa.'%')
            ->limit(2)
            ->pluck('id');

        if ($likeMatches->count() === 1) {
            return (int) $likeMatches->first();
        }

        if ($defaultCompanyId !== null) {
            $company = Company::query()->whereKey($defaultCompanyId)->first();
            if ($company) {
                return (int) $company->id;
            }
        }

        return null;
    }

    /**
     * @return array{id:int,name:string}|null
     */
    private function suggestLocation(?string $clockName): ?array
    {
        $clockName = $this->normalizeText($clockName);
        if ($clockName === null || ! Schema::hasTable('locations')) {
            return null;
        }

        $normalizedClockName = mb_strtolower($clockName);
        $locations = Location::query()
            ->select('id', 'name')
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->get();

        foreach ($locations as $location) {
            $locationName = $this->normalizeText($location->name);
            if ($locationName === null) {
                continue;
            }

            $normalizedLocationName = mb_strtolower($locationName);

            if (str_contains($normalizedClockName, $normalizedLocationName)
                || str_contains($normalizedLocationName, $normalizedClockName)) {
                return [
                    'id' => (int) $location->id,
                    'name' => $locationName,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<int, string>  $candidates
     */
    private function rowValue(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeHeader($candidate);
            $value = $row[$normalized] ?? null;
            $value = $this->normalizeText($value);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $value = str_replace('_', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return strtoupper(trim($value));
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveDefaultCompanyId(?int $defaultCompanyId): ?int
    {
        if ($defaultCompanyId !== null && $defaultCompanyId > 0) {
            return $defaultCompanyId;
        }

        $fromConfig = config('fortia.clocks_default_company_id');
        if (is_numeric($fromConfig) && (int) $fromConfig > 0) {
            return (int) $fromConfig;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $samples
     * @param  array<string, mixed>  $sample
     */
    private function pushSample(array &$samples, array $sample, int $limit = 10): void
    {
        if (count($samples) >= $limit) {
            return;
        }

        $samples[] = $sample;
    }
}
