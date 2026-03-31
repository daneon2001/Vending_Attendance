<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Company::query()
            ->withCount([
                'locations as units_count',
                'clocks',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->boolean('status') ? 1 : 0);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($companyQuery) use ($search): void {
                $companyQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $companies = $query
            ->orderBy('name')
            ->paginate($request->integer('per_page', 12));

        return response()->json([
            'data' => CompanyResource::collection($companies)->resolve(),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
                'next_page_url' => $companies->nextPageUrl(),
                'prev_page_url' => $companies->previousPageUrl(),
                'from' => $companies->firstItem(),
                'to' => $companies->lastItem(),
            ],
        ]);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = Company::create($request->validated());

        AuditLogger::log(
            'companies.created',
            $company,
            'Empresa creada',
            [
                'attributes' => $request->validated(),
                'after' => Arr::only($company->toArray(), ['name', 'code', 'status']),
            ]
        );

        return response()->json([
            'message' => 'Empresa creada correctamente',
            'data' => CompanyResource::make(
                $company->loadCount([
                    'locations as units_count',
                    'clocks',
                ])
            )->resolve(),
        ], 201);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $payload = $request->validated();
        $before = Arr::only($company->toArray(), array_keys($payload));
        $company->update($payload);

        AuditLogger::log(
            'companies.updated',
            $company,
            'Empresa actualizada',
            [
                'before' => $before,
                'after' => Arr::only($company->toArray(), array_keys($payload)),
            ]
        );

        return response()->json([
            'message' => 'Empresa actualizada',
            'data' => CompanyResource::make(
                $company->loadCount([
                    'locations as units_count',
                    'clocks',
                ])
            )->resolve(),
        ]);
    }

    public function toggleStatus(Company $company): JsonResponse
    {
        $previous = (int) $company->status;

        $company->update([
            'status' => $company->status ? 0 : 1,
        ]);

        AuditLogger::log(
            'companies.status_changed',
            $company,
            $company->status ? 'Empresa activada' : 'Empresa desactivada',
            [
                'before' => ['status' => $previous],
                'after' => ['status' => (int) $company->status],
            ]
        );

        return response()->json([
            'message' => $company->status ? 'Empresa activada' : 'Empresa desactivada',
            'data' => CompanyResource::make(
                $company->loadCount([
                    'locations as units_count',
                    'clocks',
                ])
            )->resolve(),
        ]);
    }
}
