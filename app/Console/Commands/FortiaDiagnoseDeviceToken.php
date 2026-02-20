<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FortiaDiagnoseDeviceToken extends Command
{
    protected $signature = 'fortia:diagnose-device-token';

    protected $description = 'Diagnose FortiaPrime device-token endpoints (routes, middleware and token E2E requests).';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $checks = [];

    public function handle(): int
    {
        $this->checks = [];

        $this->line('== Fortia Device Token Diagnostics ==');

        $token = trim((string) config('device.static_token', ''));
        $this->addCheck(
            name: 'config.device_static_token.present',
            pass: $token !== '',
            detail: $token !== '' ? 'DEVICE_STATIC_TOKEN configured' : 'DEVICE_STATIC_TOKEN is empty',
        );

        $this->checkRoutesAndMiddleware();

        if ($token !== '') {
            $this->runE2EChecks($token);
        }

        $this->printChecks();

        $path = $this->writeJsonReport();
        $this->info('JSON report: '.$path);

        $hasFail = collect($this->checks)
            ->contains(fn (array $check): bool => ($check['status'] ?? '') === 'FAIL');

        return $hasFail ? self::FAILURE : self::SUCCESS;
    }

    private function checkRoutesAndMiddleware(): void
    {
        $routes = [
            'attendance_from_device' => [
                'method' => 'POST',
                'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
            ],
            'clock_heartbeat' => [
                'method' => 'POST',
                'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/{clock}/heartbeat',
            ],
            'employees_catalog' => [
                'method' => 'GET',
                'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/employees/catalog',
            ],
            'employees_templates' => [
                'method' => 'GET',
                'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/employees/templates',
            ],
        ];

        foreach ($routes as $label => $definition) {
            $route = $this->findRoute((string) $definition['method'], (string) $definition['uri']);

            $this->addCheck(
                name: 'route.exists.'.$label,
                pass: $route !== null,
                detail: $route ? 'Route found' : 'Route not found',
            );

            if (! $route) {
                continue;
            }

            $middlewares = $route->gatherMiddleware();
            $hasDeviceToken = $this->middlewareContainsAny($middlewares, [
                'device.token',
                'devicetokenmiddleware',
            ]);
            $hasSanctum = $this->middlewareContains($middlewares, 'sanctum');

            $this->addCheck(
                name: 'middleware.'.$label.'.has_device_token',
                pass: $hasDeviceToken,
                detail: $hasDeviceToken ? 'device.token middleware found' : 'device.token middleware missing',
                extra: ['middlewares' => $middlewares],
            );

            $this->addCheck(
                name: 'middleware.'.$label.'.no_sanctum',
                pass: ! $hasSanctum,
                detail: ! $hasSanctum ? 'Sanctum middleware not present (expected)' : 'Sanctum middleware found (unexpected)',
                extra: ['middlewares' => $middlewares],
            );
        }
    }

    private function runE2EChecks(string $token): void
    {
        $requiredTables = [
            'locations',
            'clocks',
            'clock_logs',
            'employees',
            'devices',
            'attendance_logs',
            'employee_fingerprints',
            'employee_template_deletions',
        ];

        $missing = collect($requiredTables)
            ->filter(fn (string $table): bool => ! Schema::hasTable($table))
            ->values()
            ->all();

        if ($missing !== []) {
            $this->addCheck(
                name: 'e2e.prerequisites.tables',
                pass: false,
                detail: 'Missing required tables: '.implode(', ', $missing),
            );

            return;
        }

        DB::beginTransaction();

        try {
            $fixture = $this->createFixture();
            $headers = [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ];

            $attendance = $this->dispatchJson(
                method: 'POST',
                uri: '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
                payload: [
                    'employee_id' => $fixture['employee_id'],
                    'clock_id' => $fixture['clock_id'],
                    'location_id' => $fixture['location_id'],
                    'log_type' => 'IN',
                    'log_date' => now()->toIso8601String(),
                    'local_id' => 'diag-dt-'.Str::lower(Str::random(12)),
                    'device_timestamp' => now()->toIso8601String(),
                    'raw_payload' => ['source' => 'diagnostics'],
                ],
                server: $headers,
            );
            $this->addCheck(
                name: 'e2e.attendance_from_device.201_or_200',
                pass: in_array($attendance['status'], [200, 201], true),
                detail: 'attendance/from-device must return 200 or 201 with valid token',
                extra: $attendance,
            );

            $heartbeat = $this->dispatchJson(
                method: 'POST',
                uri: '/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/'.$fixture['clock_id'].'/heartbeat',
                payload: [
                    'monitoring_status' => 'online',
                    'program_status' => 'running',
                    'last_status_message' => 'diagnostic',
                    'device_timestamp' => now()->toIso8601String(),
                ],
                server: $headers,
            );
            $this->addCheck(
                name: 'e2e.clock_heartbeat.200',
                pass: $heartbeat['status'] === 200 && (($heartbeat['json']['success'] ?? false) === true),
                detail: 'clock heartbeat must return 200 with success=true',
                extra: $heartbeat,
            );

            $catalog = $this->dispatchJson(
                method: 'GET',
                uri: '/api/FortiaPrimeApi.Opensync/api/v2/employees/catalog',
                payload: [
                    'location_id' => $fixture['location_id'],
                    'since' => now()->subDays(2)->format('YmdHis'),
                ],
                server: $headers,
            );
            $this->addCheck(
                name: 'e2e.employees_catalog.200',
                pass: $catalog['status'] === 200 && is_array($catalog['json']['data'] ?? null),
                detail: 'employees/catalog must return 200 with data array',
                extra: $catalog,
            );

            $templates = $this->dispatchJson(
                method: 'GET',
                uri: '/api/FortiaPrimeApi.Opensync/api/v2/employees/templates',
                payload: [
                    'location_id' => $fixture['location_id'],
                    'status' => 'all',
                ],
                server: $headers,
            );
            $this->addCheck(
                name: 'e2e.employees_templates.200',
                pass: $templates['status'] === 200 && is_array($templates['json']['data'] ?? null),
                detail: 'employees/templates must return 200 with data array',
                extra: $templates,
            );
        } catch (\Throwable $exception) {
            $this->addCheck(
                name: 'e2e.runtime',
                pass: false,
                detail: 'Runtime exception: '.$exception->getMessage(),
            );
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createFixture(): array
    {
        $now = now();
        $companyId = $this->resolveCompanyId($now);

        $locationId = DB::table('locations')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Device Token Diagnostic Unit',
            'code' => 'DT-DIAG',
            'timezone' => 'America/Mexico_City',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $clockId = DB::table('clocks')->insertGetId([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'clock_name' => 'Device Token Diagnostic Clock',
            'serial_number' => 'DT-'.strtoupper(Str::random(8)),
            'status' => 1,
            'monitoring_status' => 'online',
            'program_status' => 'online',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => random_int(810000, 899999),
            'company_id' => $companyId,
            'name' => 'Device',
            'last_name' => 'Token',
            'full_name' => 'Device Token Diagnostic Employee',
            'base_location_id' => $locationId,
            'status' => 'A',
            'has_fingerprint' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('devices')->insert([
            'device_serial' => DB::table('clocks')->where('id', $clockId)->value('serial_number'),
            'clock_id' => $clockId,
            'unit_id' => $locationId,
            'company_id' => $companyId,
            'shared_secret' => 'diag-device-secret-'.Str::random(24),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => 'DT-TPL-'.strtoupper(Str::random(6)),
            'template_b64' => base64_encode('diagnostic-template'),
            'template_format' => 'zkteco-v1',
            'status' => 'enrolled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'location_id' => $locationId,
            'clock_id' => $clockId,
            'employee_id' => $employeeId,
        ];
    }

    private function resolveCompanyId(\Illuminate\Support\Carbon $now): ?int
    {
        if (! Schema::hasTable('companies')) {
            return null;
        }

        $existingCompanyId = DB::table('companies')->value('id');
        if ($existingCompanyId) {
            return (int) $existingCompanyId;
        }

        $payload = [
            'name' => 'Device Token Diagnostic Company',
        ];

        if (Schema::hasColumn('companies', 'code')) {
            $payload['code'] = 'DTDIAG';
        }
        if (Schema::hasColumn('companies', 'status')) {
            $payload['status'] = 1;
        }
        if (Schema::hasColumn('companies', 'created_at')) {
            $payload['created_at'] = $now;
        }
        if (Schema::hasColumn('companies', 'updated_at')) {
            $payload['updated_at'] = $now;
        }

        try {
            return (int) DB::table('companies')->insertGetId($payload);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $server
     * @return array<string, mixed>
     */
    private function dispatchJson(string $method, string $uri, array $payload = [], array $server = []): array
    {
        $method = strtoupper($method);
        $hasJsonBody = ! in_array($method, ['GET', 'HEAD'], true);
        $content = $hasJsonBody
            ? (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
            : null;

        $request = Request::create(
            uri: $uri,
            method: $method,
            parameters: $hasJsonBody ? [] : $payload,
            cookies: [],
            files: [],
            server: array_merge([
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_USER_AGENT' => 'fortia-diagnose-device-token/1.0',
            ], $server),
            content: $content,
        );

        $response = app()->handle($request);
        app()->terminate($request, $response);

        return $this->formatResponse($response->getStatusCode(), (string) $response->getContent(), $response->headers->all());
    }

    /**
     * @param  array<string, array<int, string>>  $headerBag
     * @return array<string, mixed>
     */
    private function formatResponse(int $status, string $content, array $headerBag): array
    {
        $json = json_decode($content, true);
        $headers = [];

        foreach ($headerBag as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => Str::limit(preg_replace('/\s+/', ' ', $content), 250, '...'),
            'body_size' => strlen($content),
            'json' => is_array($json) ? $json : null,
        ];
    }

    private function findRoute(string $method, string $uri): ?Route
    {
        $method = strtoupper($method);
        $uri = ltrim($uri, '/');

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            if (ltrim($route->uri(), '/') !== $uri) {
                continue;
            }

            if (! in_array($method, $route->methods(), true)) {
                continue;
            }

            return $route;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $middlewares
     * @param  array<int, string>  $needles
     */
    private function middlewareContainsAny(array $middlewares, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($this->middlewareContains($middlewares, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $middlewares
     */
    private function middlewareContains(array $middlewares, string $needle): bool
    {
        $needle = strtolower($needle);

        foreach ($middlewares as $middleware) {
            if (str_contains(strtolower((string) $middleware), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function addCheck(string $name, bool $pass, string $detail, array $extra = []): void
    {
        $row = [
            'name' => $name,
            'status' => $pass ? 'PASS' : 'FAIL',
            'detail' => $detail,
        ];

        if ($extra !== []) {
            $row['extra'] = $extra;
        }

        $this->checks[] = $row;
    }

    private function printChecks(): void
    {
        foreach ($this->checks as $check) {
            $this->line(sprintf(
                '[%s] %s - %s',
                $check['status'] ?? 'N/A',
                $check['name'] ?? 'unknown',
                $check['detail'] ?? ''
            ));
        }
    }

    private function writeJsonReport(): string
    {
        $report = [
            'timestamp' => now()->toIso8601String(),
            'checks' => $this->checks,
            'summary' => [
                'total' => count($this->checks),
                'passed' => collect($this->checks)->where('status', 'PASS')->count(),
                'failed' => collect($this->checks)->where('status', 'FAIL')->count(),
            ],
        ];

        $path = storage_path('app/device_token_diagnostics.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
