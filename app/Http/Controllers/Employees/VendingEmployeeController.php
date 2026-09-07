<?php

namespace App\Http\Controllers\Employees;

use App\Enums\Employees\EmployeeImportRunStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeImportRun;
use App\Services\Employees\EmployeeImportService;
use App\Services\Employees\Fortia\FortiaEmployeeClientFactory;
use App\Services\Employees\FortiaEmployeeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VendingEmployeeController extends Controller
{
    public function index(Request $request, FortiaEmployeeClientFactory $fortia)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'in:FORTIA,MANUAL,DEMO,LEGACY'],
            'status' => ['nullable', 'in:A,B'],
        ]);
        $employees = DB::table('employees')->select([
            'id', 'employee_number', 'fortia_employee_id', 'full_name', 'status', 'source', 'source_external_id', 'source_synced_at',
        ])->when($validated['q'] ?? null, fn ($query, $q) => $query->where(fn ($query) => $query->where('employee_number', 'like', '%'.$q.'%')->orWhere('full_name', 'like', '%'.$q.'%')))
            ->when($validated['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('employee_number')->paginate(25)->withQueryString();

        return Inertia::render('Employees/VendingCatalog', [
            'employees' => $employees,
            'filters' => $validated,
            'fortia' => $fortia->describe(),
            'capabilities' => ['import' => $request->user()->hasPermission('employees', 'import'), 'sync' => $request->user()->hasPermission('employees', 'sync')],
            'limits' => ['file_kb' => config('employees.import.max_file_kb'), 'rows' => config('employees.import.max_rows')],
        ]);
    }

    public function upload(Request $request, EmployeeImportService $imports)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.max(1, (int) config('employees.import.max_file_kb', 5120))],
            'source' => ['prohibited'], 'company_id' => ['prohibited'], 'employee_id' => ['prohibited'],
        ]);

        return response()->json($imports->preview($imports->stage($request->file('file'), $request->user())), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, string $uuid, EmployeeImportService $imports)
    {
        return response()->json($imports->preview($this->run($request, $uuid), $request->integer('page', 1)))->header('Cache-Control', 'no-store');
    }

    public function apply(Request $request, string $uuid, EmployeeImportService $imports)
    {
        $payload = $request->validate(['confirmed' => ['required', 'accepted'], 'preview_hash' => ['required', 'string', 'size:64']]);
        $result = $imports->apply($this->run($request, $uuid), $payload['preview_hash']);

        return response()->json(['stale' => $result['stale'], 'preview' => $imports->preview($result['run'])], $result['stale'] ? 409 : 200)
            ->header('Cache-Control', 'no-store');
    }

    public function sync(Request $request, FortiaEmployeeSyncService $service)
    {
        $request->validate(['dry_run' => ['required', 'boolean'], 'confirmed' => ['required_if:dry_run,false', 'accepted']]);
        try {
            return response()->json(['dry_run' => $request->boolean('dry_run'), 'metrics' => $service->sync($request->boolean('dry_run'))]);
        } catch (\RuntimeException $exception) {
            return response()->json(['error_code' => $exception->getMessage(), 'message' => 'La sincronización no pudo completarse. Revisa la configuración de Fortia.'], 503);
        }
    }

    private function run(Request $request, string $uuid): EmployeeImportRun
    {
        $run = EmployeeImportRun::where('uuid', $uuid)->where('initiated_by', $request->user()->id)->firstOrFail();
        abort_if($run->expires_at->isPast() || $run->status === EmployeeImportRunStatus::EXPIRED, 410, 'El preview expiró.');

        return $run;
    }
}
