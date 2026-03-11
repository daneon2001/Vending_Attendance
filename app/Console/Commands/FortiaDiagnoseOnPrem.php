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

class FortiaDiagnoseOnPrem extends Command
{
    protected $signature = 'fortia:diagnose-onprem';

    protected $description = 'Diagnose on-prem HMAC endpoints (routes, middleware and signed E2E requests).';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $checks = [];

    public function handle(): int
    {
        $this->checks = [];

        $this->line('== Fortia OnPrem Diagnostics ==');

        $this->checkRoutesAndMiddleware();
        $this->runE2EChecks();

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
            'ping' => ['method' => 'GET', 'uri' => 'api/onprem/ping'],
            'heartbeat' => ['method' => 'POST', 'uri' => 'api/onprem/heartbeat'],
            'attendances' => ['method' => 'POST', 'uri' => 'api/onprem/attendances'],
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
            $hasHmac = $this->middlewareContainsAny($middlewares, [
                'device.hmac',
                'verifydevicehmac',
            ]);
            $hasSanctum = $this->middlewareContains($middlewares, 'sanctum');

            $this->addCheck(
                name: 'middleware.'.$label.'.has_hmac',
                pass: $hasHmac,
                detail: $hasHmac ? 'HMAC middleware found' : 'HMAC middleware missing',
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

    private function runE2EChecks(): void
    {
        $requiredTables = [
            'devices',
            'device_nonces',
            'locations',
            'employees',
            'attendances_raw',
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

            $ping = $this->dispatchSignedRequest(
                method: 'GET',
                uri: '/api/onprem/ping',
                payload: [],
                deviceSerial: (string) $fixture['device_serial'],
                sharedSecret: (string) $fixture['shared_secret'],
            );

            $this->addCheck(
                name: 'e2e.ping.200',
                pass: $ping['status'] === 200 && (($ping['json']['ok'] ?? false) === true),
                detail: 'Signed ping must return 200 with ok=true',
                extra: $ping,
            );

            $heartbeatPayload = [
                'device_serial' => $fixture['device_serial'],
                'status_message' => 'diagnostic heartbeat',
                'device_ok' => true,
                'api_ok' => true,
                'pending_count' => 0,
            ];
            $heartbeat = $this->dispatchSignedRequest(
                method: 'POST',
                uri: '/api/onprem/heartbeat',
                payload: $heartbeatPayload,
                deviceSerial: (string) $fixture['device_serial'],
                sharedSecret: (string) $fixture['shared_secret'],
            );

            $this->addCheck(
                name: 'e2e.heartbeat.200',
                pass: $heartbeat['status'] === 200 && (($heartbeat['json']['ok'] ?? false) === true),
                detail: 'Signed heartbeat must return 200 with ok=true',
                extra: $heartbeat,
            );

            $utcNow = now('UTC');
            $timezone = 'America/Mexico_City';
            $localNow = $utcNow->copy()->setTimezone($timezone);

            $attendancesPayload = [
                'device_serial' => $fixture['device_serial'],
                'unit_id' => $fixture['unit_id'],
                'events' => [
                    [
                        'local_event_id' => (string) Str::uuid(),
                        'collaborator_id' => $fixture['employee_id'],
                        'punched_at_local' => $localNow->toIso8601String(),
                        'timezone' => $timezone,
                        'punched_at_utc' => $utcNow->toIso8601String(),
                        'type_inout' => 'IN',
                        'source' => 'FINGERPRINT',
                        'quality' => 85,
                        'meta' => ['diag' => true],
                    ],
                ],
            ];
            try {
                $attendances = $this->dispatchSignedRequest(
                    method: 'POST',
                    uri: '/api/onprem/attendances',
                    payload: $attendancesPayload,
                    deviceSerial: (string) $fixture['device_serial'],
                    sharedSecret: (string) $fixture['shared_secret'],
                );

                $firstStatus = $attendances['json']['received'][0]['status'] ?? null;
                $isStoredLike = in_array($firstStatus, ['STORED', 'DUPLICATE'], true);
                $this->addCheck(
                    name: 'e2e.attendances.200',
                    pass: $attendances['status'] === 200 && (($attendances['json']['ok'] ?? false) === true) && $isStoredLike,
                    detail: 'Signed attendances must return 200 with STORED/DUPLICATE',
                    extra: $attendances,
                );
            } catch (\Throwable $exception) {
                $shortMessage = $this->shortExceptionMessage($exception);
                $this->addCheck(
                    name: 'e2e.attendances.exception',
                    pass: false,
                    detail: 'Attendances request threw exception: '.$shortMessage,
                    extra: $this->buildExceptionExtra($exception, '/api/onprem/attendances'),
                );
                $this->line('[FAIL] e2e.attendances.exception - '.$shortMessage);
            }
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
        $serial = 'DIAG-ONPREM-'.strtoupper(Str::random(8));
        $secret = 'diag-secret-'.Str::random(32);
        $now = now();
        $companyId = $this->resolveCompanyId($now);

        $locationPayload = [
            'name' => 'OnPrem Diagnostic Unit',
            'code' => 'ONP-DIAG',
            'timezone' => 'America/Mexico_City',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($companyId !== null && Schema::hasColumn('locations', 'company_id')) {
            $locationPayload['company_id'] = $companyId;
        }
        $unitId = DB::table('locations')->insertGetId($locationPayload);

        $clockId = $this->resolveClockId(
            serial: $serial,
            unitId: $unitId,
            companyId: $companyId,
            now: $now,
        );

        $employeePayload = [
            'fortia_employee_id' => random_int(700001, 799999),
            'name' => 'OnPrem',
            'full_name' => 'OnPrem Diagnostic Employee',
            'base_location_id' => $unitId,
            'status' => 'A',
            'has_fingerprint' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($companyId !== null && Schema::hasColumn('employees', 'company_id')) {
            $employeePayload['company_id'] = $companyId;
        }
        $employeeId = DB::table('employees')->insertGetId($employeePayload);

        $devicePayload = [
            'device_serial' => $serial,
            'shared_secret' => $secret,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('devices', 'unit_id')) {
            $devicePayload['unit_id'] = $unitId;
        }
        if (Schema::hasColumn('devices', 'company_id')) {
            $devicePayload['company_id'] = $companyId;
        }
        if (Schema::hasColumn('devices', 'clock_id')) {
            $devicePayload['clock_id'] = $clockId;
        }
        if (Schema::hasColumn('devices', 'last_seen_at')) {
            $devicePayload['last_seen_at'] = $now;
        }
        DB::table('devices')->insert($devicePayload);

        return [
            'device_serial' => $serial,
            'shared_secret' => $secret,
            'unit_id' => $unitId,
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
            'name' => 'OnPrem Diagnostic Company',
        ];
        if (Schema::hasColumn('companies', 'code')) {
            $payload['code'] = 'ONPDIAG';
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

    private function resolveClockId(
        string $serial,
        int $unitId,
        ?int $companyId,
        \Illuminate\Support\Carbon $now,
    ): ?int {
        if (! Schema::hasTable('clocks')) {
            return null;
        }

        $existingClockId = DB::table('clocks')->value('id');
        if ($existingClockId) {
            return (int) $existingClockId;
        }

        $payload = [
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('clocks', 'clock_name')) {
            $payload['clock_name'] = 'OnPrem Diagnostic Clock';
        }
        if (Schema::hasColumn('clocks', 'serial_number')) {
            $payload['serial_number'] = $serial;
        }
        if (Schema::hasColumn('clocks', 'location_id')) {
            $payload['location_id'] = $unitId;
        }
        if (Schema::hasColumn('clocks', 'company_id')) {
            $payload['company_id'] = $companyId;
        }
        if (Schema::hasColumn('clocks', 'status')) {
            $payload['status'] = 1;
        }
        if (Schema::hasColumn('clocks', 'monitoring_status')) {
            $payload['monitoring_status'] = 'online';
        }
        if (Schema::hasColumn('clocks', 'program_status')) {
            $payload['program_status'] = 'online';
        }

        try {
            return (int) DB::table('clocks')->insertGetId($payload);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dispatchSignedRequest(
        string $method,
        string $uri,
        array $payload,
        string $deviceSerial,
        string $sharedSecret,
    ): array {
        $method = strtoupper($method);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $rawBody = in_array($method, ['GET', 'HEAD'], true)
            ? ''
            : (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        $bodyHash = hash('sha256', $rawBody);
        $canonical = $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash;
        $signature = base64_encode(hash_hmac('sha256', $canonical, $sharedSecret, true));

        $request = Request::create(
            uri: $uri,
            method: $method,
            parameters: in_array($method, ['GET', 'HEAD'], true) ? $payload : [],
            cookies: [],
            files: [],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_DEVICE_SERIAL' => $deviceSerial,
                'HTTP_X_TIMESTAMP' => $timestamp,
                'HTTP_X_NONCE' => $nonce,
                'HTTP_X_SIGNATURE' => $signature,
                'HTTP_USER_AGENT' => 'fortia-diagnose-onprem/1.0',
            ],
            content: $rawBody,
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

    /**
     * @return array<string, mixed>
     */
    private function buildExceptionExtra(\Throwable $exception, string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
            'top_stack' => $this->extractTopStack($exception),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractTopStack(\Throwable $exception, int $maxFrames = 5): array
    {
        return collect($exception->getTrace())
            ->take($maxFrames)
            ->map(function (array $frame): array {
                return [
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'class' => $frame['class'] ?? null,
                    'function' => $frame['function'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function shortExceptionMessage(\Throwable $exception): string
    {
        $message = preg_replace('/\s+/', ' ', trim($exception->getMessage()));

        return Str::limit((string) $message, 180, '...');
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

        $path = storage_path('app/onprem_diagnostics.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
