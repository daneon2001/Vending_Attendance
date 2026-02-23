<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditBiometricAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $employeeParam = $request->route('employee');
        $employee = $employeeParam instanceof Employee ? $employeeParam : null;
        $employeeId = $employee ? (int) $employee->id : (is_numeric($employeeParam) ? (int) $employeeParam : null);

        $rawFingerprintIds = $request->attributes->get('biometric_fingerprint_ids', []);
        $fingerprintIds = is_array($rawFingerprintIds)
            ? array_values(array_map('intval', $rawFingerprintIds))
            : [];

        $path = trim($request->path(), '/');
        $isDeleteRoute = $request->isMethod('DELETE')
            && preg_match('#^api/admin/employees/[^/]+/fingerprints$#', $path) === 1;
        $includeTemplates = str_contains($path, '/templates');
        $statusCode = $response->getStatusCode();
        $granted = $statusCode >= 200 && $statusCode < 300;

        $event = $isDeleteRoute
            ? 'biometric.fingerprint.deleted'
            : ($includeTemplates
                ? 'biometric.fingerprints.templates.accessed'
                : 'biometric.fingerprints.metadata.accessed');
        $reason = $isDeleteRoute
            ? ($granted ? 'fingerprints_deleted' : 'access_denied')
            : ($granted
                ? ($includeTemplates ? 'templates_read' : 'metadata_read')
                : 'access_denied');
        $description = $isDeleteRoute
            ? ($granted ? 'Biometric fingerprints deleted' : 'Biometric fingerprint delete denied')
            : ($granted
                ? 'Biometric fingerprint access granted'
                : 'Biometric fingerprint access denied');
        $deletedCount = (int) $request->attributes->get('biometric_deleted_count', 0);
        $requestId = (string) ($request->attributes->get('request_id') ?? '');
        $correlationId = (string) ($request->attributes->get('correlation_id') ?? '');

        AuditLogger::log(
            event: $event,
            auditable: $employee,
            description: $description,
            metadata: [
                'action' => $isDeleteRoute ? 'biometric.fingerprint.deleted' : 'biometric.template.accessed',
                'entity' => 'employee_fingerprints',
                'entity_id' => $employeeId !== null ? (string) $employeeId : null,
                'reason' => $reason,
                'new_values' => [
                    'employee_id' => $employeeId,
                    'fingerprint_ids' => $fingerprintIds,
                    'deleted_count' => $deletedCount,
                    'user_id' => optional($request->user())->id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'route' => $request->path(),
                    'request_id' => $requestId,
                    'correlation_id' => $correlationId,
                    'records_count' => count($fingerprintIds),
                    'include_templates' => $includeTemplates,
                    'is_delete' => $isDeleteRoute,
                    'access_granted' => $granted,
                    'response_status' => $statusCode,
                ],
            ],
        );

        return $response;
    }
}
