<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\CreateMissingEmployeeCatalogsRequest;
use App\Http\Requests\Employees\ImportEmployeesExcelRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\EmployeeExcelImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
        $stage = 'service.import';

        try {
            $result = $service->import($request->file('file'));
            if (! ($result['can_import'] ?? false)) {
                $stage = 'audit.import_failed';
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

                Log::info('employees.import.response.prepare', [
                    'status' => 422,
                    'can_import' => false,
                    'file_name' => $result['file_name'] ?? null,
                    'summary' => $result['summary'] ?? [],
                ]);

                $stage = 'response.prepare.validation_failed';
                return response()->json([
                    'message' => $result['message'] ?? 'El archivo contiene errores y no se puede importar.',
                ] + $result, 422);
            }

            $stage = 'audit.import_completed';
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

            Log::info('employees.import.response.prepare', [
                'status' => 200,
                'can_import' => true,
                'file_name' => $result['file_name'] ?? null,
                'summary' => $result['summary'] ?? [],
            ]);

            $stage = 'response.prepare.success';
            return response()->json($result);
        } catch (Throwable $exception) {
            Log::error('employees.import.controller.failed', [
                'stage' => $stage,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'exception_trace' => array_slice($exception->getTrace(), 0, 8),
                'file_name' => $request->file('file')?->getClientOriginalName(),
            ]);

            $status = $stage === 'service.import' ? 422 : 500;

            return response()->json([
                'message' => 'No se pudo procesar el archivo Excel.',
                'detail' => $exception->getMessage(),
            ], $status);
        }
    }
}
