<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitDetailResource;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Services\Audit\AuditLogger;
use App\Services\Units\UnitCatalogQueryService;
use App\Services\Units\UnitBulkMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UnitController extends Controller
{
    public function index(Request $request, UnitCatalogQueryService $unitCatalogQueryService): JsonResponse
    {
        $filters = [
            'company_id' => $request->input('company_id'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ];

        $units = $unitCatalogQueryService->buildFilteredListQuery($filters)
            ->orderBy('name')
            ->paginate($request->integer('per_page', 12));
        $summary = $unitCatalogQueryService->buildSummary();

        return response()->json([
            'data' => UnitResource::collection($units)->resolve(),
            'summary' => $summary,
            'columns' => $this->unitColumnConfig(),
            'filtered_meta' => [
                'filtered_total' => $units->total(),
                'current_page_count' => $units->count(),
            ],
            'meta' => [
                'current_page' => $units->currentPage(),
                'last_page' => $units->lastPage(),
                'per_page' => $units->perPage(),
                'total' => $units->total(),
                'next_page_url' => $units->nextPageUrl(),
                'prev_page_url' => $units->previousPageUrl(),
                'from' => $units->firstItem(),
                'to' => $units->lastItem(),
            ],
        ]);
    }

    public function export(Request $request, UnitCatalogQueryService $unitCatalogQueryService): StreamedResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'status' => ['nullable'],
            'search' => ['nullable', 'string', 'max:150'],
            'format' => ['nullable', 'in:csv,excel'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', 'max:80'],
        ]);

        $filters = [
            'company_id' => $validated['company_id'] ?? null,
            'status' => $validated['status'] ?? null,
            'search' => $validated['search'] ?? null,
        ];
        $format = $validated['format'] ?? 'csv';
        $columns = $this->normalizeRequestedColumns($validated['columns'] ?? []);
        $units = $unitCatalogQueryService->buildFilteredListQuery($filters)
            ->orderBy('name')
            ->get();
        $filenameBase = 'catalogo_unidades_'.now()->format('Ymd_His');
        $columnMap = collect($this->availableColumns())->keyBy('key');

        AuditLogger::log(
            'units.exported',
            null,
            'Exportación de catálogo de unidades',
            [
                'action' => 'export',
                'entity' => 'locations',
                'reason' => 'manual_export',
                'new_values' => [
                    'format' => $format,
                    'filters' => $filters,
                    'columns' => $columns,
                    'records_count' => $units->count(),
                    'timezone' => 'America/Mexico_City',
                ],
            ]
        );

        $streamCallback = function () use ($units, $columns, $columnMap, $filters): void {
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, ['Exportado', now('America/Mexico_City')->format('Y-m-d H:i:s'), 'Timezone', 'America/Mexico_City']);
            fputcsv($output, ['Filtros', json_encode($filters, JSON_UNESCAPED_UNICODE)]);
            fputcsv($output, collect($columns)->map(fn (string $key) => $columnMap[$key]['label'] ?? $key)->all());

            foreach ($units as $unit) {
                $row = UnitResource::make($unit)->resolve();
                fputcsv($output, $this->exportValuesForUnit($row, $columns));
            }

            fclose($output);
        };

        if ($format === 'excel') {
            return response()->streamDownload($streamCallback, $filenameBase.'.xls', [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            ]);
        }

        return response()->streamDownload($streamCallback, $filenameBase.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function bulkDeactivationPreview(UnitBulkMaintenanceService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->previewCandidates(),
        ]);
    }

    public function bulkDeactivateInactive(Request $request, UnitBulkMaintenanceService $service): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $result = $service->bulkDeactivate((bool) ($validated['dry_run'] ?? false));

        return response()->json($result);
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        $unit = Unit::create($request->validated());

        AuditLogger::log(
            'units.created',
            $unit,
            'Unidad creada',
            [
                'attributes' => $request->validated(),
            ]
        );

        return response()->json([
            'message' => 'Unidad creada correctamente',
            'data' => UnitResource::make($unit->load('company'))->resolve(),
        ], 201);
    }

    public function show(Unit $unit): JsonResponse
    {
        $unit->load(['company', 'clocks' => function ($query) {
            $query->select('id', 'location_id', 'clock_name', 'ip_address', 'monitoring_status', 'status', 'last_heartbeat_at');
        }]);

        return response()->json([
            'data' => UnitDetailResource::make($unit)->resolve(),
        ]);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        $before = $unit->only(array_keys($request->validated()));
        $unit->update($request->validated());

        AuditLogger::log(
            'units.updated',
            $unit,
            'Unidad actualizada',
            [
                'before' => $before,
                'after' => Arr::only($unit->toArray(), array_keys($request->validated())),
            ]
        );

        return response()->json([
            'message' => 'Unidad actualizada',
            'data' => UnitResource::make($unit->load('company'))->resolve(),
        ]);
    }

    public function toggleStatus(Unit $unit): JsonResponse
    {
        $previous = $unit->status;
        $unit->update([
            'status' => $unit->status ? 0 : 1,
        ]);

        AuditLogger::log(
            'units.status_changed',
            $unit,
            $unit->status ? 'Unidad activada' : 'Unidad desactivada',
            [
                'before' => $previous ? 'activa' : 'inactiva',
                'after' => $unit->status ? 'activa' : 'inactiva',
            ]
        );

        return response()->json([
            'message' => $unit->status ? 'Unidad activada' : 'Unidad desactivada',
            'data' => UnitResource::make($unit->load('company'))->resolve(),
        ]);
    }

    protected function unitColumnConfig(): array
    {
        return [
            'available' => $this->availableColumns(),
            'default' => $this->defaultColumns(),
        ];
    }

    public function availableColumns(): array
    {
        return [
            ['key' => 'company_name', 'label' => 'Empresa'],
            ['key' => 'name', 'label' => 'Unidad'],
            ['key' => 'code', 'label' => 'Código'],
            ['key' => 'status_label', 'label' => 'Estado'],
            ['key' => 'city', 'label' => 'Ciudad'],
            ['key' => 'clocks_count', 'label' => 'Relojes'],
            ['key' => 'last_heartbeat_at', 'label' => 'Último heartbeat'],
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'fortia_location_id', 'label' => 'Fortia location ID'],
            ['key' => 'description', 'label' => 'Descripción'],
            ['key' => 'state', 'label' => 'Estado geográfico'],
            ['key' => 'country', 'label' => 'País'],
            ['key' => 'timezone', 'label' => 'Timezone'],
            ['key' => 'address', 'label' => 'Dirección'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'company_id', 'label' => 'Company ID'],
            ['key' => 'active_clocks_count', 'label' => 'Relojes activos'],
            ['key' => 'offline_clocks_count', 'label' => 'Relojes offline'],
            ['key' => 'created_at', 'label' => 'Creado'],
            ['key' => 'updated_at', 'label' => 'Actualizado'],
            ['key' => 'actions', 'label' => 'Acciones', 'exportable' => false, 'locked' => true],
        ];
    }

    public function defaultColumns(): array
    {
        return ['company_name', 'name', 'code', 'status_label', 'city', 'clocks_count', 'last_heartbeat_at', 'actions'];
    }

    /**
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    protected function normalizeRequestedColumns(array $requested): array
    {
        $allowed = collect($this->availableColumns())
            ->filter(fn (array $column) => ($column['exportable'] ?? true) !== false)
            ->pluck('key')
            ->all();

        $columns = collect($requested)
            ->filter(fn ($key) => is_string($key) && in_array($key, $allowed, true))
            ->values()
            ->all();

        return $columns !== []
            ? $columns
            : collect($this->defaultColumns())
                ->filter(fn (string $key) => in_array($key, $allowed, true))
                ->values()
                ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columns
     * @return array<int, mixed>
     */
    protected function exportValuesForUnit(array $row, array $columns): array
    {
        return collect($columns)->map(fn (string $key) => match ($key) {
            'company_name' => $row['company']['name'] ?? null,
            'name' => $row['name'] ?? null,
            'code' => $row['code'] ?? null,
            'status_label' => $row['status_label'] ?? null,
            'city' => $row['city'] ?? null,
            'clocks_count' => $row['clocks_count'] ?? 0,
            'last_heartbeat_at' => $row['last_heartbeat_at_display'] ?? null,
            'id' => $row['id'] ?? null,
            'fortia_location_id' => $row['fortia_location_id'] ?? null,
            'description' => $row['description'] ?? null,
            'state' => $row['state'] ?? null,
            'country' => $row['country'] ?? null,
            'timezone' => $row['timezone'] ?? null,
            'address' => $row['address'] ?? null,
            'status' => $row['status'] ?? null,
            'company_id' => $row['company']['id'] ?? null,
            'active_clocks_count' => $row['active_clocks_count'] ?? 0,
            'offline_clocks_count' => $row['offline_clocks_count'] ?? 0,
            'created_at' => $row['created_at_display'] ?? null,
            'updated_at' => $row['updated_at_display'] ?? null,
            default => null,
        })->all();
    }
}
