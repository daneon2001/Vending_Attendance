<?php

namespace App\Http\Controllers;

use App\Exports\AttendanceCardExport;
use App\Services\Attendance\AttendanceCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceCardController extends Controller
{
    public function __construct(
        protected AttendanceCardService $attendanceCardService
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->validateFilters($request, false);

        return Inertia::render('AttendanceCards/Index', $this->attendanceCardService->buildPagePayload($filters));
    }

    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        $filters = $this->validateFilters($request, true);
        $payload = $this->attendanceCardService->buildExportPayload($filters);

        if (data_get($payload, 'card.employee.id') === null) {
            return redirect()
                ->route('attendance-cards.index', $filters)
                ->with('warning', 'Selecciona un empleado valido para exportar la tarjeta.');
        }

        return Excel::download(
            new AttendanceCardExport(
                payload: $payload,
                logoPath: $this->attendanceCardService->exportLogoPath(),
            ),
            $this->attendanceCardService->buildExportFilename($payload)
        );
    }

    private function validateFilters(Request $request, bool $requireEmployee): array
    {
        $employeeRules = ['nullable', 'integer', 'exists:employees,id'];

        if ($requireEmployee) {
            array_unshift($employeeRules, 'required');
        }

        return $request->validate([
            'employee_id' => $employeeRules,
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'department_id' => ['nullable', 'integer'],
            'period' => ['nullable', Rule::in([
                'today',
                'current_week',
                'previous_week',
                'current_fortnight',
                'previous_fortnight',
                'current_month',
                'previous_month',
                'custom',
            ])],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }
}
