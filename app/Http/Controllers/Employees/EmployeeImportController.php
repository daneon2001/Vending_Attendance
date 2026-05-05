<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\CreateMissingEmployeeCatalogsRequest;
use App\Http\Requests\Employees\ImportEmployeesExcelRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\EmployeeExcelImportService;
use Illuminate\Http\JsonResponse;
use Throwable;

class EmployeeImportController extends Controller
{
    public function preview(
        ImportEmployeesExcelRequest $request,
        EmployeeExcelImportService $service
    ): JsonResponse {
        try {
            $result = $service->preview($request->file('file'));
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'No se pudo leer el archivo Excel.',
                'detail' => $exception->getMessage(),
            ], 422);
        }

        AuditLogger::log(
            'employees.import_preview',
            null,
            'Vista previa de importacion de empleados',
            [
                'action' => 'employees.import_preview',
                'entity' => 'employees',
                'reason' => 'preview_generated',
                'after' => $result['summary'] ?? [],
                'file_name' => $result['file_name'] ?? null,
            ]
        );

        if ((int) data_get($result, 'catalogs.missing_total', 0) > 0) {
            AuditLogger::log(
                'employees.import_catalogs_detected',
                null,
                'Catalogos faltantes detectados en importacion de empleados',
                [
                    'action' => 'employees.import_catalogs_detected',
                    'entity' => 'employees',
                    'reason' => 'missing_catalogs_detected',
                    'after' => $result['catalogs'] ?? [],
                    'file_name' => $result['file_name'] ?? null,
                ]
            );
        }

        return response()->json($result);
    }

    public function createMissingCatalogs(
        CreateMissingEmployeeCatalogsRequest $request,
        EmployeeExcelImportService $service
    ): JsonResponse {
        try {
            $result = $service->createMissingCatalogs($request->file('file'));
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'No se pudieron crear los catalogos faltantes.',
                'detail' => $exception->getMessage(),
            ], 422);
        }

        AuditLogger::log(
            'employees.import_catalogs_created',
            null,
            'Catalogos creados desde importacion de empleados',
            [
                'action' => 'employees.import_catalogs_created',
                'entity' => 'employees',
                'reason' => 'missing_catalogs_created',
                'after' => $result['created_catalogs'] ?? [],
                'file_name' => data_get($result, 'preview.file_name'),
            ]
        );

        AuditLogger::log(
            'employees.import_preview_recalculated',
            null,
            'Vista previa recalculada despues de crear catalogos',
            [
                'action' => 'employees.import_preview_recalculated',
                'entity' => 'employees',
                'reason' => 'preview_recalculated',
                'after' => data_get($result, 'preview.summary', []),
                'file_name' => data_get($result, 'preview.file_name'),
            ]
        );

        return response()->json($result);
    }

    public function store(
        ImportEmployeesExcelRequest $request,
        EmployeeExcelImportService $service
    ): JsonResponse {
        try {
            $result = $service->import($request->file('file'));
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'No se pudo procesar el archivo Excel.',
                'detail' => $exception->getMessage(),
            ], 422);
        }

        if (! ($result['can_import'] ?? false)) {
            AuditLogger::log(
                'employees.import_failed',
                null,
                'Importacion de empleados rechazada por errores de validacion',
                [
                    'action' => 'employees.import',
                    'entity' => 'employees',
                    'reason' => 'preview_contains_errors',
                    'after' => $result['summary'] ?? [],
                    'file_name' => $result['file_name'] ?? null,
                ]
            );

            return response()->json([
                'message' => $result['message'] ?? 'El archivo contiene errores y no se puede importar.',
            ] + $result, 422);
        }

        AuditLogger::log(
            'employees.import_completed',
            null,
            'Importacion manual de empleados completada',
            [
                'action' => 'employees.import',
                'entity' => 'employees',
                'reason' => 'import_completed',
                'after' => $result['summary'] ?? [],
                'file_name' => $result['file_name'] ?? null,
            ]
        );

        return response()->json($result);
    }
}
