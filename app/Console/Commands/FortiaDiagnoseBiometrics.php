<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FortiaDiagnoseBiometrics extends Command
{
    protected $signature = 'fortia:diagnose-biometrics';

    protected $description = 'Diagnose E2E biometric endpoints and compact catalog (Sanctum SPA and Bearer modes).';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $checks = [];

    public function handle(): int
    {
        $this->checks = [];

        $this->line('== Fortia Biometrics Diagnostics ==');

        $this->checkRouteDeclarationsInFiles();
        $this->checkRoutesAndMiddleware();
        $this->runE2EChecks();

        $this->printChecks();

        $path = $this->writeJsonReport();
        $this->info('JSON report: '.$path);

        $hasFail = collect($this->checks)
            ->contains(fn (array $check): bool => ($check['status'] ?? '') === 'FAIL');

        return $hasFail ? self::FAILURE : self::SUCCESS;
    }

    private function checkRouteDeclarationsInFiles(): void
    {
        $apiPath = base_path('routes/api.php');
        $webPath = base_path('routes/web.php');

        $apiContent = File::exists($apiPath) ? (string) File::get($apiPath) : '';
        $webContent = File::exists($webPath) ? (string) File::get($webPath) : '';

        $apiExpectations = [
            'compact_route' =>
                Str::contains($apiContent, 'AdminEmployeeController::class')
                && Str::contains($apiContent, "Route::prefix('admin')"),
            'fingerprints_route' => Str::contains($apiContent, 'employees/{employee}/fingerprints'),
            'templates_route' => Str::contains($apiContent, 'employees/{employee}/fingerprints/templates'),
            'face_route' => Str::contains($apiContent, 'employees/{employee}/face-profile'),
        ];

        foreach ($apiExpectations as $name => $ok) {
            $this->addCheck(
                name: 'file.api.'.$name,
                pass: $ok,
                detail: $ok
                    ? 'Route declaration found in routes/api.php'
                    : 'Expected declaration missing from routes/api.php',
            );
        }

        $webUnexpected = [
            'compact_route' =>
                Str::contains($webContent, 'AdminEmployeeController::class')
                || Str::contains($webContent, '/api/admin/employees'),
            'fingerprints_route' =>
                Str::contains($webContent, 'employees/{employee}/fingerprints')
                || Str::contains($webContent, '/api/admin/employees/{employee}/fingerprints'),
            'templates_route' =>
                Str::contains($webContent, 'employees/{employee}/fingerprints/templates')
                || Str::contains($webContent, '/api/superadmin/employees/{employee}/fingerprints/templates'),
            'face_route' =>
                Str::contains($webContent, 'employees/{employee}/face-profile')
                || Str::contains($webContent, '/api/admin/employees/{employee}/face-profile'),
        ];

        foreach ($webUnexpected as $name => $existsInWeb) {
            $this->addCheck(
                name: 'file.web.absent.'.$name,
                pass: ! $existsInWeb,
                detail: ! $existsInWeb
                    ? 'Route declaration not found in routes/web.php (expected)'
                    : 'Route declaration exists in routes/web.php and must be removed',
            );
        }
    }

    private function checkRoutesAndMiddleware(): void
    {
        $routes = [
            'compact' => [
                'method' => 'GET',
                'uri' => 'api/admin/employees',
                'middleware_checks' => [],
            ],
            'fingerprints' => [
                'method' => 'GET',
                'uri' => 'api/admin/employees/{employee}/fingerprints',
                'middleware_checks' => [
                    'auth_sanctum' => ['auth:sanctum', 'sanctum'],
                    'ensure_role' => ['ensurerole', 'role:'],
                    'ensure_strict_permission' => ['ensurestrictpermission', 'perm.strict:biometrics,fingerprints.read'],
                    'throttle' => ['throttle:biometrics-fingerprints', 'throttlerequests:biometrics-fingerprints'],
                    'audit_biometric_access' => ['auditbiometricaccess', 'audit.biometric'],
                ],
            ],
            'templates' => [
                'method' => 'GET',
                'uri' => 'api/superadmin/employees/{employee}/fingerprints/templates',
                'middleware_checks' => [
                    'auth_sanctum' => ['auth:web,sanctum', 'auth:sanctum', 'sanctum'],
                    'ensure_role' => ['ensurerole', 'role:'],
                    'ensure_strict_permission' => ['ensurestrictpermission', 'perm.strict:biometrics,templates.read'],
                    'throttle' => ['throttle:biometrics-templates', 'throttlerequests:biometrics-templates'],
                    'audit_biometric_access' => ['auditbiometricaccess', 'audit.biometric'],
                ],
            ],
            'face' => [
                'method' => 'PATCH',
                'uri' => 'api/admin/employees/{employee}/face-profile',
                'middleware_checks' => [
                    'auth_sanctum' => ['auth:web,sanctum', 'sanctum'],
                    'ensure_role' => ['ensurerole', 'role:'],
                    'ensure_strict_permission' => ['ensurestrictpermission', 'perm.strict:biometrics,face.manage'],
                    'throttle' => ['throttle:biometrics-face', 'throttlerequests:biometrics-face'],
                    'audit_biometric_access' => ['auditbiometricaccess', 'audit.biometric'],
                ],
            ],
        ];

        foreach ($routes as $label => $config) {
            $route = $this->findRoute((string) $config['method'], (string) $config['uri']);

            $this->addCheck(
                name: 'route.exists.'.$label,
                pass: $route !== null,
                detail: $route ? 'Route found' : 'Route not found',
            );

            if (! $route) {
                continue;
            }

            $middlewares = $route->gatherMiddleware();
            foreach ((array) $config['middleware_checks'] as $checkName => $needles) {
                $hasMiddleware = $this->middlewareContainsAny($middlewares, (array) $needles);

                $this->addCheck(
                    name: 'middleware.'.$label.'.'.$checkName,
                    pass: $hasMiddleware,
                    detail: $hasMiddleware
                        ? 'Middleware requirement satisfied'
                        : 'Expected middleware was not found',
                    extra: ['middlewares' => $middlewares],
                );
            }
        }

        foreach ([
            ['GET', 'api/employees/{employee}'],
            ['POST', 'api/employees/{employee}/fingerprints'],
        ] as [$method, $uri]) {
            $route = $this->findRoute($method, $uri);
            $this->addCheck(
                name: 'route.absent.'.strtolower($method).'.'.str_replace(['/', '{', '}'], ['.', '', ''], $uri),
                pass: $route === null,
                detail: $route === null ? 'Legacy public biometric route is absent' : 'Legacy public biometric route is still registered',
            );
        }
    }

    private function runE2EChecks(): void
    {
        $requiredTables = [
            'users',
            'roles',
            'permissions',
            'role_user',
            'permission_role',
            'employees',
            'employee_fingerprints',
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
            $fixtures = $this->createFixtures();
            if (! $fixtures) {
                return;
            }

            $employeeId = (int) $fixtures['employee_id'];

            $noAuthFingerprints = $this->dispatchJson('GET', "/api/admin/employees/{$employeeId}/fingerprints");
            $this->addCheck(
                name: 'e2e.no_auth.fingerprints_401',
                pass: $noAuthFingerprints['status'] === 401,
                detail: 'No-auth request to fingerprints must return 401',
                extra: $noAuthFingerprints,
            );

            $noAuthTemplates = $this->dispatchJson('GET', "/api/superadmin/employees/{$employeeId}/fingerprints/templates");
            $this->addCheck(
                name: 'e2e.no_auth.templates_401',
                pass: $noAuthTemplates['status'] === 401,
                detail: 'No-auth request to templates must return 401',
                extra: $noAuthTemplates,
            );

            $noAuthFace = $this->dispatchJson('PATCH', "/api/admin/employees/{$employeeId}/face-profile", [
                'face_enabled' => false,
                'face_status' => 'disabled',
            ]);
            $this->addCheck(
                name: 'e2e.no_auth.face_401',
                pass: $noAuthFace['status'] === 401,
                detail: 'No-auth request to face profile must return 401',
                extra: $noAuthFace,
            );

            $this->runBearerModeChecks($fixtures);
            $this->runSpaModeChecks($fixtures);
        } catch (\Throwable $exception) {
            $this->addCheck(
                name: 'e2e.runtime',
                pass: false,
                detail: 'Runtime exception: '.$exception->getMessage(),
            );
        } finally {
            DB::rollBack();
            $this->clearAuthState();
        }
    }

    /**
     * @param  array<string, mixed>  $fixtures
     */
    private function runBearerModeChecks(array $fixtures): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            $this->addCheck(
                name: 'e2e.bearer.prerequisite.personal_access_tokens',
                pass: false,
                detail: 'Table personal_access_tokens is missing; Bearer checks cannot run.',
            );

            return;
        }

        /** @var User $admin */
        $admin = $fixtures['admin_user'];
        /** @var User $superadmin */
        $superadmin = $fixtures['superadmin_user'];
        $employeeId = (int) $fixtures['employee_id'];

        $adminToken = $admin->createToken('diag-admin')->plainTextToken;
        $superToken = $superadmin->createToken('diag-super')->plainTextToken;

        $this->clearAuthState();
        $adminFingerprints = $this->dispatchJson(
            'GET',
            "/api/admin/employees/{$employeeId}/fingerprints",
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$adminToken],
        );
        $this->addCheck(
            name: 'e2e.bearer.admin.fingerprints_200',
            pass: $adminFingerprints['status'] === 200,
            detail: 'Bearer admin request to fingerprints must return 200',
            extra: $adminFingerprints,
        );

        $this->clearAuthState();
        $adminTemplates = $this->dispatchJson(
            'GET',
            "/api/superadmin/employees/{$employeeId}/fingerprints/templates",
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$adminToken],
        );
        $this->addCheck(
            name: 'e2e.bearer.admin.templates_403',
            pass: $adminTemplates['status'] === 403,
            detail: 'Bearer admin request to templates must return 403',
            extra: $adminTemplates,
        );

        $this->clearAuthState();
        $superTemplates = $this->dispatchJson(
            'GET',
            "/api/superadmin/employees/{$employeeId}/fingerprints/templates",
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$superToken],
        );
        $this->addCheck(
            name: 'e2e.bearer.superadmin.templates_200',
            pass: $superTemplates['status'] === 200,
            detail: 'Bearer superadmin request to templates must return 200',
            extra: $superTemplates,
        );

        $cacheControl = strtolower((string) ($superTemplates['headers']['cache-control'] ?? ''));
        $hasNoStore = str_contains($cacheControl, 'no-store');
        $this->addCheck(
            name: 'e2e.bearer.templates.header_no_store',
            pass: $hasNoStore,
            detail: $hasNoStore
                ? 'Template response includes Cache-Control no-store'
                : 'Template response is missing Cache-Control no-store',
            extra: $superTemplates,
        );

        $this->clearAuthState();
    }

    /**
     * @param  array<string, mixed>  $fixtures
     */
    private function runSpaModeChecks(array $fixtures): void
    {
        /** @var User $admin */
        $admin = $fixtures['admin_user'];
        $employeeId = (int) $fixtures['employee_id'];

        $spaHeaders = [
            'HTTP_ORIGIN' => (string) config('app.url'),
            'HTTP_REFERER' => rtrim((string) config('app.url'), '/').'/employees',
        ];

        $this->clearAuthState();
        $this->setSpaUser($admin);

        $spaAdminFingerprints = $this->dispatchJson(
            'GET',
            "/api/admin/employees/{$employeeId}/fingerprints",
            [],
            $spaHeaders,
        );
        $this->addCheck(
            name: 'e2e.spa.admin.fingerprints_200',
            pass: $spaAdminFingerprints['status'] === 200,
            detail: 'SPA-mode admin request to fingerprints must return 200',
            extra: $spaAdminFingerprints,
        );

        $spaAdminTemplates = $this->dispatchJson(
            'GET',
            "/api/superadmin/employees/{$employeeId}/fingerprints/templates",
            [],
            $spaHeaders,
        );
        $this->addCheck(
            name: 'e2e.spa.admin.templates_403',
            pass: $spaAdminTemplates['status'] === 403,
            detail: 'SPA-mode admin request to templates must return 403',
            extra: $spaAdminTemplates,
        );

        $spaCompact = $this->dispatchJson(
            'GET',
            '/api/admin/employees?page=1&per_page=15',
            [],
            $spaHeaders,
        );

        $compactBody = (string) ($spaCompact['raw_body'] ?? '');
        $compactBodySize = (int) ($spaCompact['body_size'] ?? strlen($compactBody));
        $compactJson = is_array($spaCompact['json'] ?? null) ? $spaCompact['json'] : null;

        $this->addCheck(
            name: 'e2e.spa.admin.compact_200',
            pass: $spaCompact['status'] === 200,
            detail: 'SPA-mode admin request to compact catalog must return 200',
            extra: $spaCompact,
        );

        $this->addCheck(
            name: 'e2e.spa.admin.compact_ok_true',
            pass: $compactJson !== null && (($compactJson['ok'] ?? null) === true),
            detail: 'Compact catalog response includes ok=true',
            extra: $spaCompact,
        );

        $this->addCheck(
            name: 'e2e.spa.admin.compact_no_template_b64',
            pass: ! str_contains(strtolower($compactBody), 'template_b64'),
            detail: 'Compact catalog response does not include template_b64',
            extra: ['body_size' => $compactBodySize],
        );

        $this->addCheck(
            name: 'e2e.spa.admin.compact_lt_100kb',
            pass: $compactBodySize < (100 * 1024),
            detail: 'Compact catalog response is below 100KB',
            extra: ['body_size' => $compactBodySize],
        );

        $this->clearAuthState();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function createFixtures(): ?array
    {
        $stamp = now()->format('YmdHis').'-'.Str::lower(Str::random(6));
        $now = now();

        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => random_int(900000, 999999),
            'name' => 'Diagnostic',
            'last_name' => 'Biometric',
            'full_name' => 'Diagnostic Biometric',
            'status' => 'A',
            'has_fingerprint' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('employee_fingerprints')->insert([
            'employee_id' => $employeeId,
            'vendor_template_id' => 'DIAG-TPL-'.$stamp,
            'template_b64' => str_repeat('AAABBBCCC', 80),
            'template_format' => 'zkteco-v1',
            'enrolment_type' => 'FINGERPRINT',
            'status' => 'enrolled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $adminUserId = DB::table('users')->insertGetId($this->buildUserPayload(
            name: 'Diag Admin',
            email: "diag-admin-{$stamp}@example.test",
        ));
        $superUserId = DB::table('users')->insertGetId($this->buildUserPayload(
            name: 'Diag SuperAdmin',
            email: "diag-super-{$stamp}@example.test",
        ));

        $adminRoleId = $this->upsertRole('Administrador');
        $superRoleId = $this->upsertRole('superadmin');

        $fingerprintsPermissionId = $this->upsertPermission('biometrics', 'fingerprints.read');
        $templatesPermissionId = $this->upsertPermission('biometrics', 'templates.read');
        $employeesViewPermissionId = $this->upsertPermission('employees', 'view');

        $this->attachRoleToUser($adminRoleId, $adminUserId);
        $this->attachRoleToUser($superRoleId, $superUserId);

        $this->attachPermissionToRole($adminRoleId, $fingerprintsPermissionId);
        $this->attachPermissionToRole($adminRoleId, $employeesViewPermissionId);
        $this->attachPermissionToRole($superRoleId, $templatesPermissionId);

        $adminUser = User::query()->find($adminUserId);
        $superUser = User::query()->find($superUserId);

        if (! $adminUser || ! $superUser) {
            $this->addCheck(
                name: 'e2e.fixtures.users',
                pass: false,
                detail: 'Failed to create diagnostic users.',
            );

            return null;
        }

        return [
            'employee_id' => $employeeId,
            'admin_user' => $adminUser,
            'superadmin_user' => $superUser,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchJson(string $method, string $uri, array $payload = [], array $server = []): array
    {
        $baseServer = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'fortia-diagnose-biometrics/1.0',
        ];

        $request = Request::create(
            uri: $uri,
            method: strtoupper($method),
            parameters: $payload,
            cookies: [],
            files: [],
            server: array_merge($baseServer, $server),
        );
        $request->setUserResolver(function (?string $guard = null) {
            if ($guard !== null) {
                return Auth::guard($guard)->user();
            }

            return Auth::user();
        });

        $response = app()->handle($request);
        app()->terminate($request, $response);

        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);

        $headers = [];
        foreach ($response->headers->all() as $name => $value) {
            $headers[strtolower($name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => Str::limit(preg_replace('/\s+/', ' ', $content), 250, '...'),
            'body_size' => strlen($content),
            'json' => is_array($decoded) ? $decoded : null,
            'raw_body' => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUserPayload(string $name, string $email): array
    {
        $payload = [
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(Str::random(20)),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('users', 'estatus')) {
            $payload['estatus'] = true;
        }

        return $payload;
    }

    private function upsertRole(string $name): int
    {
        DB::table('roles')->updateOrInsert(
            ['name' => $name],
            [
                'description' => 'Diagnostic role '.$name,
                'is_system' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return (int) DB::table('roles')->where('name', $name)->value('id');
    }

    private function upsertPermission(string $module, string $action): int
    {
        DB::table('permissions')->updateOrInsert(
            [
                'module' => $module,
                'action' => $action,
            ],
            [
                'name' => "{$module}.{$action}",
                'description' => "Diagnostic permission {$module}.{$action}",
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return (int) DB::table('permissions')
            ->where('module', $module)
            ->where('action', $action)
            ->value('id');
    }

    private function attachRoleToUser(int $roleId, int $userId): void
    {
        DB::table('role_user')->updateOrInsert(
            [
                'role_id' => $roleId,
                'user_id' => $userId,
            ],
            [
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function attachPermissionToRole(int $roleId, int $permissionId): void
    {
        DB::table('permission_role')->updateOrInsert(
            [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ],
            [
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function clearAuthState(): void
    {
        try {
            Auth::guard('web')->logout();
        } catch (\Throwable $exception) {
            // no-op
        }

        Auth::forgetGuards();
    }

    private function setSpaUser(User $user): void
    {
        Auth::shouldUse('web');
        Auth::setUser($user);
        Auth::guard('web')->setUser($user);
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

        $path = storage_path('app/biometrics_diagnostics.json');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
