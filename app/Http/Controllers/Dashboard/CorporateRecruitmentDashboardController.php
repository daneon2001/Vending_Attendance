<?php

namespace App\Http\Controllers\Dashboard;

use App\Exports\CorporateRecruitmentDashboardExport;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\CorporateRecruitmentDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorporateRecruitmentDashboardController extends Controller
{
    public function __construct(
        protected CorporateRecruitmentDashboardService $service
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->validateFilters($request, false);
        $catalog = $this->service->buildCatalog($filters);

        return Inertia::render('Dashboard/CorporateRecruitment', [
            'initialFilters' => $catalog['filters'],
            'locations' => $catalog['locations'],
            'clocks' => $catalog['clocks'],
            'timezone' => $catalog['timezone'],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request, false);

        return response()->json($this->service->build($filters));
    }

    public function export(Request $request): BinaryFileResponse|StreamedResponse
    {
        $filters = $this->validateFilters($request, true);
        $exportData = $this->service->buildExportData($filters);
        $filename = $this->service->buildExportFilename($exportData['filters'], $exportData['generated_at']);

        if (($filters['format'] ?? 'xlsx') === 'csv') {
            return $this->service->streamCsvExport($exportData, $filename.'.csv');
        }

        return Excel::download(
            new CorporateRecruitmentDashboardExport($exportData),
            $filename.'.xlsx'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateFilters(Request $request, bool $withFormat): array
    {
        $rules = [
            'range' => ['nullable', Rule::in([
                CorporateRecruitmentDashboardService::RANGE_TODAY,
                CorporateRecruitmentDashboardService::RANGE_YESTERDAY,
                CorporateRecruitmentDashboardService::RANGE_CURRENT_WEEK,
                CorporateRecruitmentDashboardService::RANGE_FORTNIGHT,
                CorporateRecruitmentDashboardService::RANGE_CUSTOM,
            ])],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'unit_id' => ['nullable', 'integer', 'exists:locations,id'],
            'clock_id' => ['nullable', 'integer', 'exists:clocks,id'],
        ];

        if ($withFormat) {
            $rules['format'] = ['nullable', Rule::in(['xlsx', 'csv'])];
        }

        return $request->validate($rules);
    }
}
