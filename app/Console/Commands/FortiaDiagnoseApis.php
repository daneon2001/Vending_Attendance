<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class FortiaDiagnoseApis extends Command
{
    protected $signature = 'fortia:diagnose-apis {--base=}';

    protected $description = 'Diagnostica endpoints device/login (rutas, middleware, respuestas JSON).';

    public function handle(): int
    {
        $baseUrl = $this->resolveBaseUrl();
        $envToken = env('DEVICE_STATIC_TOKEN');
        $cfgToken = config('device.static_token');
        $token = is_string($cfgToken) ? $cfgToken : '';

        $this->line('== Fortia API Diagnostics ==');
        $this->line('APP_ENV: '.(string) config('app.env'));
        $this->line('APP_URL: '.(string) config('app.url'));
        $this->line('Base usada: '.$baseUrl);
        $this->line('DEVICE_STATIC_TOKEN: '.$this->maskToken($token));

        $global = [];
        if ($envToken === null || trim((string) $envToken) === '') {
            $global[] = [
                'name' => 'runtime_token',
                'status' => 'FAIL',
                'reason' => 'env(DEVICE_STATIC_TOKEN) vacio o null.',
            ];
        } else {
            $global[] = [
                'name' => 'runtime_token',
                'status' => 'PASS',
                'reason' => 'env(DEVICE_STATIC_TOKEN) presente.',
            ];
        }

        if ((string) $envToken !== (string) $cfgToken) {
            $global[] = [
                'name' => 'config_cache_consistency',
                'status' => 'WARN',
                'reason' => 'env/token != config(token). Sugerencia: php artisan optimize:clear',
            ];
        } else {
            $global[] = [
                'name' => 'config_cache_consistency',
                'status' => 'PASS',
                'reason' => 'Config y env consistentes.',
            ];
        }

        $routes = $this->checkRequiredRoutes();
        $middleware = $this->checkMiddlewareBindings();
        $ids = $this->resolveDiagnosticIds();
        $http = $this->runInternalChecks($token, $ids);

        $this->printSection('Configuracion', $global);
        $this->printSection('Rutas', $routes);
        $this->printSection('Middleware', $middleware);
        $this->printSection('HTTP checks', $http);

        $report = [
            'timestamp' => now()->toIso8601String(),
            'app_url' => (string) config('app.url'),
            'base_used' => $baseUrl,
            'device_token_masked' => $this->maskToken($token),
            'global' => $global,
            'routes' => $routes,
            'middleware' => $middleware,
            'http' => $http,
        ];

        $path = storage_path('app/api_diagnostics.json');
        @file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Reporte JSON: '.$path);

        Log::info('fortia.diagnose_apis', $report);

        $hasFail = collect([$global, $routes, $middleware, $http])->flatten(1)->contains(
            fn (array $row) => ($row['status'] ?? '') === 'FAIL'
        );

        return $hasFail ? self::FAILURE : self::SUCCESS;
    }

    private function resolveBaseUrl(): string
    {
        $base = trim((string) $this->option('base'));
        if ($base !== '') {
            return rtrim($base, '/');
        }

        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl === '') {
            return 'http://localhost';
        }

        return rtrim($appUrl, '/');
    }

    private function checkRequiredRoutes(): array
    {
        $required = [
            ['method' => 'POST', 'uri' => 'api/device/ping'],
            ['method' => 'POST', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/login/authenticate'],
            ['method' => 'GET', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/employees/catalog'],
            ['method' => 'GET', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/employees/templates'],
            ['method' => 'POST', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device'],
            ['method' => 'POST', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/{clock}/heartbeat'],
            ['method' => 'POST', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/heartbeat'],
        ];

        $rows = [];
        foreach ($required as $item) {
            $route = $this->findRoute($item['method'], $item['uri']);
            $rows[] = [
                'name' => $item['method'].' /'.$item['uri'],
                'status' => $route ? 'PASS' : 'FAIL',
                'reason' => $route ? 'OK' : 'Ruta no existe (sugerencia: php artisan route:list | findstr "'.$item['uri'].'")',
            ];
        }

        return $rows;
    }

    private function checkMiddlewareBindings(): array
    {
        $rows = [];

        $deviceRoutes = [
            ['method' => 'POST', 'uri' => 'api/device/ping', 'expect_device' => true],
            ['method' => 'POST', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device', 'expect_device' => true],
            ['method' => 'POST', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/{clock}/heartbeat', 'expect_device' => true],
            ['method' => 'GET', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/employees/catalog', 'expect_device' => true],
            ['method' => 'GET', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/employees/templates', 'expect_device' => true],
            ['method' => 'POST', 'uri' => 'api/FortiaPrimeApi.Opensync/api/v2/login/authenticate', 'expect_device' => false],
        ];

        foreach ($deviceRoutes as $def) {
            $route = $this->findRoute($def['method'], $def['uri']);
            if (! $route) {
                $rows[] = [
                    'name' => $def['method'].' /'.$def['uri'],
                    'status' => 'FAIL',
                    'reason' => 'Ruta faltante',
                ];
                continue;
            }

            $middlewares = $route->gatherMiddleware();
            $hasDevice = in_array('device.token', $middlewares, true);
            $expected = (bool) $def['expect_device'];

            if ($expected && ! $hasDevice) {
                $rows[] = [
                    'name' => $def['method'].' /'.$def['uri'],
                    'status' => 'FAIL',
                    'reason' => 'Deberia tener device.token',
                ];
                continue;
            }

            if (! $expected && $hasDevice) {
                $rows[] = [
                    'name' => $def['method'].' /'.$def['uri'],
                    'status' => 'FAIL',
                    'reason' => 'No debe tener device.token',
                ];
                continue;
            }

            $rows[] = [
                'name' => $def['method'].' /'.$def['uri'],
                'status' => 'PASS',
                'reason' => $expected ? 'Protegida con device.token' : 'Sin device.token',
            ];
        }

        return $rows;
    }

    private function runInternalChecks(string $token, array $ids): array
    {
        $headers = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($token !== '') {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $clockId = $ids['clock_id'];
        $locationId = $ids['location_id'];
        $employeeId = $ids['employee_id'];

        return [
            $this->dispatchCheck('device/ping ok', 'POST', '/api/device/ping', [200], $headers, []),
            $this->dispatchCheck('device/ping no token', 'POST', '/api/device/ping', [401], [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ], []),
            $this->dispatchCheck(
                'attendance/from-device',
                'POST',
                '/api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device',
                [200, 201, 422],
                $headers,
                [
                    'employee_id' => $employeeId,
                    'clock_id' => $clockId,
                    'location_id' => $locationId,
                    'log_type' => 'IN',
                    'log_date' => now()->toIso8601String(),
                    'local_id' => 'DIAG-'.Str::upper(Str::random(8)),
                    'device_timestamp' => now()->toIso8601String(),
                    'raw_payload' => ['source' => 'fortia:diagnose-apis'],
                ]
            ),
            $this->dispatchCheck(
                'heartbeat v2',
                'POST',
                '/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/'.$clockId.'/heartbeat',
                [200, 422],
                $headers,
                [
                    'monitoring_status' => 'online',
                    'last_status_message' => 'diagnose',
                    'program_status' => 'diagnose',
                    'device_timestamp' => now()->toIso8601String(),
                ]
            ),
            $this->dispatchCheck(
                'employees/catalog',
                'GET',
                '/api/FortiaPrimeApi.Opensync/api/v2/employees/catalog?since='.now()->subDays(30)->format('YmdHis').'&location_id='.$locationId,
                [200, 401],
                $headers,
                []
            ),
            $this->dispatchCheck(
                'employees/templates',
                'GET',
                '/api/FortiaPrimeApi.Opensync/api/v2/employees/templates',
                [200, 401],
                $headers,
                []
            ),
            $this->dispatchCheck(
                'login/authenticate',
                'POST',
                '/api/FortiaPrimeApi.Opensync/api/v2/login/authenticate',
                [200, 401, 422],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                ],
                [
                    'user' => 'diagnostic',
                    'password' => 'diagnostic',
                ],
                true
            ),
        ];
    }

    private function dispatchCheck(
        string $name,
        string $method,
        string $uri,
        array $expected,
        array $headers,
        array $payload,
        bool $noDevice = false
    ): array {
        try {
            $request = Request::create($uri, strtoupper($method), $payload, [], [], $headers);
            $response = app()->handle($request);

            $status = $response->getStatusCode();
            $contentType = (string) $response->headers->get('Content-Type');
            $isHtml = Str::contains(strtolower($contentType), 'text/html');
            $isRedirect = $status >= 300 && $status < 400;

            $okStatus = in_array($status, $expected, true);
            $ok = $okStatus && ! $isHtml && ! $isRedirect;

            $reason = $ok ? 'OK' : 'HTTP '.$status;
            if ($isHtml) {
                $reason = 'Respuesta HTML (se esperaba JSON)';
            } elseif ($isRedirect) {
                $reason = 'Redirect '.$status.' (revisar middleware o rutas)';
            }

            $snippet = Str::limit(preg_replace('/\s+/', ' ', (string) $response->getContent()), 200, '...');

            return [
                'name' => $name,
                'status' => $ok ? 'PASS' : 'FAIL',
                'reason' => $reason,
                'actual_status' => $status,
                'content_type' => $contentType,
                'body' => $snippet,
            ];
        } catch (\Throwable $e) {
            return [
                'name' => $name,
                'status' => 'FAIL',
                'reason' => 'Exception: '.$e->getMessage(),
                'actual_status' => null,
                'content_type' => '',
                'body' => '',
            ];
        }
    }

    private function printSection(string $title, array $rows): void
    {
        $this->newLine();
        $this->line('== '.$title.' ==');
        foreach ($rows as $row) {
            $this->line('['.($row['status'] ?? 'N/A').'] '.($row['name'] ?? 'N/A').' - '.($row['reason'] ?? ''));
        }
    }

    private function findRoute(string $method, string $uri): ?Route
    {
        $method = strtoupper($method);
        $uri = ltrim($uri, '/');

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            if (! in_array($method, $route->methods(), true)) {
                continue;
            }
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        return null;
    }

    private function resolveDiagnosticIds(): array
    {
        $locationId = 1;
        $clockId = 1;
        $employeeId = 1;

        if (DB::getSchemaBuilder()->hasTable('locations')) {
            $locationId = (int) (DB::table('locations')->value('id') ?? 1);
        }

        if (DB::getSchemaBuilder()->hasTable('clocks')) {
            $clockId = (int) (DB::table('clocks')->value('id') ?? 1);
        }

        if (DB::getSchemaBuilder()->hasTable('employees')) {
            $employeeId = (int) (DB::table('employees')->whereNotNull('company_id')->value('id')
                ?? DB::table('employees')->value('id')
                ?? 1);
        }

        return [
            'location_id' => max(1, $locationId),
            'clock_id' => max(1, $clockId),
            'employee_id' => max(1, $employeeId),
        ];
    }

    private function maskToken(string $token): string
    {
        if ($token === '') {
            return '[EMPTY]';
        }

        $len = strlen($token);
        if ($len <= 6) {
            return str_repeat('*', $len).' (len='.$len.')';
        }

        return '***'.substr($token, -6).' (len='.$len.')';
    }
}
