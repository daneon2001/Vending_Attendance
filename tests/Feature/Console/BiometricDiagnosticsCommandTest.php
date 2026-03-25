<?php

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BiometricDiagnosticsCommandTest extends TestCase
{
    private bool $createdUsersTable = false;
    private bool $createdRolesTable = false;
    private bool $createdRoleUserTable = false;
    private bool $createdPermissionsTable = false;
    private bool $createdPermissionRoleTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdPersonalAccessTokensTable = false;
    private bool $createdAuditLogsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        $this->deleteReportFile();

        if ($this->createdAuditLogsTable && Schema::hasTable('audit_logs')) {
            Schema::drop('audit_logs');
        }
        if ($this->createdPersonalAccessTokensTable && Schema::hasTable('personal_access_tokens')) {
            Schema::drop('personal_access_tokens');
        }
        if ($this->createdEmployeeFingerprintsTable && Schema::hasTable('employee_fingerprints')) {
            Schema::drop('employee_fingerprints');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdPermissionRoleTable && Schema::hasTable('permission_role')) {
            Schema::drop('permission_role');
        }
        if ($this->createdPermissionsTable && Schema::hasTable('permissions')) {
            Schema::drop('permissions');
        }
        if ($this->createdRoleUserTable && Schema::hasTable('role_user')) {
            Schema::drop('role_user');
        }
        if ($this->createdRolesTable && Schema::hasTable('roles')) {
            Schema::drop('roles');
        }
        if ($this->createdUsersTable && Schema::hasTable('users')) {
            Schema::drop('users');
        }

        parent::tearDown();
    }

    public function test_biometric_diagnostics_command_reports_routes_middlewares_and_headers(): void
    {
        $this->deleteReportFile();

        $this->artisan('fortia:diagnose-biometrics')
            ->assertExitCode(0);

        $reportPath = storage_path('app/biometrics_diagnostics.json');
        $this->assertFileExists($reportPath);

        $report = json_decode((string) File::get($reportPath), true);
        $this->assertIsArray($report);

        $checks = collect($report['checks'] ?? [])->keyBy('name');

        $expectedPassChecks = [
            'file.api.compact_route',
            'file.api.fingerprints_route',
            'file.api.templates_route',
            'file.web.absent.compact_route',
            'file.web.absent.fingerprints_route',
            'file.web.absent.templates_route',
            'route.exists.compact',
            'route.exists.fingerprints',
            'route.exists.templates',
            'middleware.fingerprints.auth_sanctum',
            'middleware.fingerprints.ensure_role',
            'middleware.fingerprints.ensure_strict_permission',
            'middleware.fingerprints.throttle',
            'middleware.fingerprints.audit_biometric_access',
            'middleware.templates.auth_sanctum',
            'middleware.templates.ensure_role',
            'middleware.templates.ensure_strict_permission',
            'middleware.templates.throttle',
            'middleware.templates.audit_biometric_access',
            'e2e.no_auth.fingerprints_401',
            'e2e.no_auth.templates_401',
            'e2e.bearer.admin.fingerprints_200',
            'e2e.bearer.admin.templates_403',
            'e2e.bearer.superadmin.templates_200',
            'e2e.bearer.templates.header_no_store',
            'e2e.spa.admin.fingerprints_200',
            'e2e.spa.admin.templates_403',
            'e2e.spa.admin.compact_200',
            'e2e.spa.admin.compact_ok_true',
            'e2e.spa.admin.compact_no_template_b64',
            'e2e.spa.admin.compact_lt_100kb',
        ];

        foreach ($expectedPassChecks as $checkName) {
            $status = $checks->get($checkName)['status'] ?? null;
            $this->assertSame('PASS', $status, "Check {$checkName} did not PASS.");
        }
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->boolean('estatus')->default(true);
                $table->rememberToken();
                $table->timestamps();
            });
            $this->createdUsersTable = true;
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('description')->nullable();
                $table->boolean('is_system')->default(false);
                $table->timestamps();
            });
            $this->createdRolesTable = true;
        }

        if (! Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
            });
            $this->createdRoleUserTable = true;
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table): void {
                $table->id();
                $table->string('module');
                $table->string('action');
                $table->string('name');
                $table->string('description')->nullable();
                $table->timestamps();
            });
            $this->createdPermissionsTable = true;
        }

        if (! Schema::hasTable('permission_role')) {
            Schema::create('permission_role', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->timestamps();
            });
            $this->createdPermissionRoleTable = true;
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->nullable()->unique();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('base_location_name')->nullable();
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('second_last_name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('status', 20)->default('A');
                $table->boolean('has_fingerprint')->default(false);
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }

        if (! Schema::hasTable('employee_fingerprints')) {
            Schema::create('employee_fingerprints', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('vendor_template_id')->nullable();
                $table->longText('template_b64')->nullable();
                $table->string('template_format', 40)->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('status', 30)->default('enrolled');
                $table->string('device_serial')->nullable();
                $table->dateTime('performed_at')->nullable();
                $table->timestamps();
            });
            $this->createdEmployeeFingerprintsTable = true;
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
            $this->createdPersonalAccessTokensTable = true;
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_type', 40)->nullable();
                $table->string('actor_identifier', 191)->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_email')->nullable();
                $table->string('event');
                $table->string('action', 60)->nullable();
                $table->string('entity', 120)->nullable();
                $table->string('entity_id', 120)->nullable();
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->text('description')->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('request_id', 64)->nullable();
                $table->string('correlation_id', 64)->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->dateTime('occurred_at_utc')->nullable();
                $table->dateTime('occurred_at_local')->nullable();
                $table->string('timezone', 64)->nullable();
                $table->timestamps();
            });
            $this->createdAuditLogsTable = true;
        }
    }

    private function cleanData(): void
    {
        foreach ([
            'audit_logs',
            'personal_access_tokens',
            'employee_fingerprints',
            'employees',
            'permission_role',
            'permissions',
            'role_user',
            'roles',
            'users',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    private function deleteReportFile(): void
    {
        $reportPath = storage_path('app/biometrics_diagnostics.json');
        if (File::exists($reportPath)) {
            File::delete($reportPath);
        }
    }
}
