<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardSummaryService $summaryService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:today,7d,30d,custom'],
            'from_date' => ['required_if:range,custom', 'nullable', 'date_format:d/m/Y'],
            'to_date' => ['required_if:range,custom', 'nullable', 'date_format:d/m/Y'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'unit_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        return response()->json($this->summaryService->build($validated));
    }
}
