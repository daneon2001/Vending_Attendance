<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExternalEmployeeLookupApiTest extends TestCase
{
    private const URI = '/api/external/employees';

    private bool $createdEmployeesTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.employee_lookup_api.token', 'external-secret-token');
        config()->set('operations.timezone', 'America/Mexico_City');

        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }

        parent::tearDown();
    }

    public function test_rejects_request_without_token(): void
    {
        $response = $this->getJson(self::URI.'/123');

        $response->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token requerido.',
            ]);
    }

    public function test_rejects_request_with_invalid_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
        ])->getJson(self::URI.'/123');

        $response->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token inválido.',
            ]);
    }

    public function test_returns_existing_employee(): void
    {
        $employeeId = $this->createEmployee();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer external-secret-token',
        ])->getJson(self::URI.'/'.$employeeId);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $employeeId)
            ->assertJsonPath('data.fortia_employee_id', '12345')
            ->assertJsonPath('data.employee_code', '000123')
            ->assertJsonPath('data.full_name', 'NOMBRE EMPLEADO')
            ->assertJsonPath('data.status', 'A')
            ->assertJsonPath('data.company_id', 1)
            ->assertJsonPath('data.base_location_id', 10)
            ->assertJsonPath('data.department_id', 5)
            ->assertJsonPath('data.can_check_all_branches', true)
            ->assertJsonPath('data.check_scope', 'ANY_BRANCH')
            ->assertJsonPath('data.has_fingerprint', true)
            ->assertJsonPath('data.has_face_enrollment', false)
            ->assertJsonPath('data.face_enabled', false);

        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }

    public function test_returns_404_when_employee_does_not_exist(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer external-secret-token',
        ])->getJson(self::URI.'/999999');

        $response->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'Empleado no encontrado.',
            ]);
    }

    public function test_does_not_expose_sensitive_fields(): void
    {
        $employeeId = $this->createEmployee();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer external-secret-token',
        ])->getJson(self::URI.'/'.$employeeId);

        $response->assertOk();

        $payload = $response->json('data');

        $this->assertArrayNotHasKey('curp', $payload);
        $this->assertArrayNotHasKey('rfc', $payload);
        $this->assertArrayNotHasKey('imss_number', $payload);
        $this->assertArrayNotHasKey('face_meta', $payload);
        $this->assertArrayNotHasKey('template_b64', $payload);
        $this->assertArrayNotHasKey('fingerprints', $payload);
    }

    public function test_supports_lookup_by_fortia_employee_id_with_consistent_json_shape(): void
    {
        $this->createEmployee();

        $response = $this->withHeaders([
            'X-Employee-Api-Token' => 'external-secret-token',
        ])->getJson(self::URI.'/12345?lookup_by=fortia');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'fortia_employee_id',
                    'employee_code',
                    'full_name',
                    'name',
                    'last_name',
                    'second_last_name',
                    'status',
                    'company_id',
                    'company_name',
                    'base_location_id',
                    'base_location_name',
                    'department_id',
                    'department_name',
                    'can_check_all_branches',
                    'check_scope',
                    'has_fingerprint',
                    'has_face_enrollment',
                    'face_enabled',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.fortia_employee_id', '12345');
    }

    private function createEmployee(): int
    {
        return DB::table('employees')->insertGetId([
            'fortia_employee_id' => 12345,
            'employee_code' => '000123',
            'full_name' => 'NOMBRE EMPLEADO',
            'name' => 'NOMBRE',
            'last_name' => 'EMPLEADO',
            'second_last_name' => 'PRUEBA',
            'status' => 'A',
            'company_id' => 1,
            'company_name' => 'Medical Life',
            'base_location_id' => 10,
            'base_location_name' => 'Unidad Centro',
            'department_id' => 5,
            'department_name' => 'Operaciones',
            'can_check_all_branches' => true,
            'check_scope' => 'ANY_BRANCH',
            'has_fingerprint' => true,
            'has_face_enrollment' => false,
            'face_enabled' => false,
            'rfc' => 'NOEX123456ABC',
            'curp' => 'NOEX123456HDFABC01',
            'imss_number' => '9876543210',
            'updated_at' => '2026-05-25 18:00:00',
            'created_at' => '2026-05-25 18:00:00',
        ]);
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->nullable()->unique();
                $table->string('employee_code')->nullable();
                $table->string('full_name')->nullable();
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('second_last_name')->nullable();
                $table->string('status', 20)->default('A');
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('company_name')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('base_location_name')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->string('department_name')->nullable();
                $table->boolean('can_check_all_branches')->default(false);
                $table->string('check_scope', 40)->nullable();
                $table->boolean('has_fingerprint')->default(false);
                $table->boolean('has_face_enrollment')->default(false);
                $table->boolean('face_enabled')->default(false);
                $table->string('rfc')->nullable();
                $table->string('curp')->nullable();
                $table->string('imss_number')->nullable();
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }

        foreach ([
            'employee_code' => fn (Blueprint $table) => $table->string('employee_code')->nullable()->after('fortia_employee_id'),
            'last_name' => fn (Blueprint $table) => $table->string('last_name')->nullable()->after('name'),
            'second_last_name' => fn (Blueprint $table) => $table->string('second_last_name')->nullable()->after('last_name'),
            'company_id' => fn (Blueprint $table) => $table->unsignedBigInteger('company_id')->nullable()->after('status'),
            'company_name' => fn (Blueprint $table) => $table->string('company_name')->nullable()->after('company_id'),
            'base_location_id' => fn (Blueprint $table) => $table->unsignedBigInteger('base_location_id')->nullable()->after('company_name'),
            'base_location_name' => fn (Blueprint $table) => $table->string('base_location_name')->nullable()->after('base_location_id'),
            'department_id' => fn (Blueprint $table) => $table->unsignedBigInteger('department_id')->nullable()->after('base_location_name'),
            'department_name' => fn (Blueprint $table) => $table->string('department_name')->nullable()->after('department_id'),
            'can_check_all_branches' => fn (Blueprint $table) => $table->boolean('can_check_all_branches')->default(false)->after('department_name'),
            'check_scope' => fn (Blueprint $table) => $table->string('check_scope', 40)->nullable()->after('can_check_all_branches'),
            'has_fingerprint' => fn (Blueprint $table) => $table->boolean('has_fingerprint')->default(false)->after('check_scope'),
            'has_face_enrollment' => fn (Blueprint $table) => $table->boolean('has_face_enrollment')->default(false)->after('has_fingerprint'),
            'face_enabled' => fn (Blueprint $table) => $table->boolean('face_enabled')->default(false)->after('has_face_enrollment'),
            'rfc' => fn (Blueprint $table) => $table->string('rfc')->nullable()->after('face_enabled'),
            'curp' => fn (Blueprint $table) => $table->string('curp')->nullable()->after('rfc'),
            'imss_number' => fn (Blueprint $table) => $table->string('imss_number')->nullable()->after('curp'),
        ] as $column => $definition) {
            if (! Schema::hasColumn('employees', $column)) {
                Schema::table('employees', $definition);
            }
        }
    }

    private function cleanData(): void
    {
        DB::table('employees')->delete();
    }
}
