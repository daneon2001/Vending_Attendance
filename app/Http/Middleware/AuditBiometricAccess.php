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

        $includeTemplates = str_contains($request->path(), '/templates');
        $statusCode = $response->getStatusCode();
        $granted = $statusCode >= 200 && $statusCode < 300;

        AuditLogger::log(
            event: $includeTemplates
                ? 'biometric.fingerprints.templates.accessed'
                : 'biometric.fingerprints.metadata.accessed',
            auditable: $employee,
            description: $granted
                ? 'Biometric fingerprint access granted'
                : 'Biometric fingerprint access denied',
            metadata: [
                'action' => 'biometric.template.accessed',
                'entity' => 'employee_fingerprints',
                'entity_id' => $employeeId !== null ? (string) $employeeId : null,
                'reason' => $granted
                    ? ($includeTemplates ? 'templates_read' : 'metadata_read')
                    : 'access_denied',
                'new_values' => [
                    'employee_id' => $employeeId,
                    'fingerprint_ids' => $fingerprintIds,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'route' => $request->path(),
                    'request_id' => (string) ($request->attributes->get('request_id') ?? ''),
                    'records_count' => count($fingerprintIds),
                    'include_templates' => $includeTemplates,
                    'access_granted' => $granted,
                    'response_status' => $statusCode,
                ],
            ],
        );

        return $response;
    }
}
