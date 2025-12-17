<?php

namespace App\Http\Controllers\Api\FortiaMock;

use App\Http\Controllers\Controller;
use App\Models\FortiaMockEmployee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FortiaMockEmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);

        $query = FortiaMockEmployee::on('fortia_mock')->query();

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('updated_since')) {
            $timestamp = Carbon::parse($request->string('updated_since'));
            $query->where('updated_at', '>=', $timestamp);
        }

        $paginator = $query->orderByDesc('updated_at')->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
            'company_name' => ['nullable', 'string'],
            'employee_id' => ['required', 'integer'],
            'name' => ['required', 'string'],
            'last_name' => ['required', 'string'],
            'second_last_name' => ['nullable', 'string'],
            'status' => ['required', 'string'],
            'base_location_id' => ['required', 'integer'],
            'base_location_name' => ['required', 'string'],
            'department_id' => ['required', 'integer'],
            'department_name' => ['required', 'string'],
            'rfc' => ['nullable', 'string'],
            'imss_number' => ['nullable', 'string'],
            'curp' => ['nullable', 'string'],
            'email_company' => ['nullable', 'string'],
        ]);

        $payload = array_merge([
            'company_name' => $validated['company_name'] ?? 'Medical Life Demo',
        ], $validated);

        $record = FortiaMockEmployee::on('fortia_mock')->updateOrCreate(
            [
                'company_id' => $payload['company_id'],
                'employee_id' => $payload['employee_id'],
            ],
            $payload
        );

        return response()->json([
            'message' => 'Empleado mock registrado.',
            'employee' => $record,
        ], 201);
    }

    public function updateStatus(Request $request, int $employee): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['A', 'B', 'active', 'inactive'])],
        ]);

        $mock = FortiaMockEmployee::on('fortia_mock')->findOrFail($employee);
        $mock->status = in_array($validated['status'], ['active', 'A'], true) ? 'A' : 'B';
        $mock->updated_at = now();
        $mock->save();

        return response()->json([
            'message' => 'Estado actualizado.',
            'employee' => $mock,
        ]);
    }
}
