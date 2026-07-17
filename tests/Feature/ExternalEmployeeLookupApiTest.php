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
    private bool $createdEmployeeDetailsTable = false;
    private bool $createdPuestosTable = false;
    private bool $createdEmployeeImportMetadataTable = false;

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
        if ($this->createdEmployeeDetailsTable && Schema::hasTable('employee_details')) {
            Schema::drop('employee_details');
        }

        if ($this->createdPuestosTable && Schema::hasTable('puestos')) {
            Schema::drop('puestos');
        }

        if ($this->createdEmployeeImportMetadataTable && Schema::hasTable('employee_import_metadata')) {
            Schema::drop('employee_import_metadata');
        }

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

    public function test_returns_existing_employee_by_internal_id_including_position(): void
    {
        $employeeId = $this->createEmployeeWithPosition();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer external-secret-token',
        ])->getJson(self::URI.'/'.$employeeId);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $employeeId)
            ->assertJsonPath('data.fortia_employee_id', '12015')
            ->assertJsonPath('data.employee_code', '000123')
            ->assertJsonPath('data.full_name', 'ANDRADE CRUZ DANIEL')
            ->assertJsonPath('data.status', 'A')
            ->assertJsonPath('data.company_id', 1)
            ->assertJsonPath('data.base_location_id', 10)
            ->assertJsonPath('data.department_id', 5)
            ->assertJsonPath('data.position_id', 123)
            ->assertJsonPath('data.position_code', '2001')
            ->assertJsonPath('data.position_name', 'ABOGADO')
            ->assertJsonPath('data.position.id', 123)
            ->assertJsonPath('data.position.code', '2001')
            ->assertJsonPath('data.position.name', 'ABOGADO')
            ->assertJsonPath('data.can_check_all_branches', true)
            ->assertJsonPath('data.check_scope', 'ANY_BRANCH')
            ->assertJsonPath('data.has_fingerprint', true)
            ->assertJsonPath('data.has_face_enrollment', false)
            ->assertJsonPath('data.face_enabled', false)
            ->assertJsonPath('data.fecha_alta', '2024-01-15')
            ->assertJsonPath('data.fecha_baja', '2026-06-30');

        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }

    public function test_returns_existing_employee_by_fortia_employee_id_including_position(): void
    {
        $employeeId = $this->createEmployeeWithPosition();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer external-secret-token',
        ])->getJson(self::URI.'/fortia/12015');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $employeeId)
            ->assertJsonPath('data.fortia_employee_id', '12015')
            ->assertJsonPath('data.full_name', 'ANDRADE CRUZ DANIEL')
            ->assertJsonPath('data.position_id', 123)
            ->assertJsonPath('data.position_code', '2001')
            ->assertJsonPath('data.position_name', 'ABOGADO')
            ->assertJsonPath('data.fecha_alta', '2024-01-15')
            ->assertJsonPath('data.fecha_baja', '2026-06-30');
    }

    public function test_employee_without_position_returns_null_position_fields(): void
    {
        $employeeId = $this->createEmployeeWithoutPosition();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer external-secret-token',
        ])->getJson(self::URI.'/'.$employeeId);

        $response->assertOk()
            ->assertJsonPath('data.position_id', null)
            ->assertJsonPath('data.position_code', null)
            ->assertJsonPath('data.position_name', null)
            ->assertJsonPath('data.position', null);
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

    public function test_returns_404_when_fortia_employee_id_does_not_exist(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer external-secret-token',
        ])->getJson(self::URI.'/fortia/999999');

        $response->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'Empleado no encontrado.',
            ]);
    }

    public function test_does_not_expose_sensitive_fields(): void
    {
        $employeeId = $this->createEmployeeWithPosition();

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

    public function test_fortia_lookup_uses_consistent_json_shape(): void
    {
        $this->createEmployeeWithPosition();

        $response = $this->withHeaders([
            'X-Employee-Api-Token' => 'external-secret-token',
        ])->getJson(self::URI.'/fortia/12015');

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
                    'position_id',
                    'position_code',
                    'position_name',
                    'position',
                    'can_check_all_branches',
                    'check_scope',
                    'has_fingerprint',
                    'has_face_enrollment',
                    'face_enabled',
                    'fecha_alta',
                    'fecha_baja',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.fortia_employee_id', '12015');
    }

    private function createEmployeeWithPosition(): int
    {
        $employeeId = $this->createEmployee(12015, 'ANDRADE CRUZ DANIEL');

        DB::table('puestos')->insert([
            'id' => 123,
            'cla_puesto' => '2001',
            'nom_puesto' => 'ABOGADO',
            'created_at' => '2026-05-25 18:00:00',
            'updated_at' => '2026-05-25 18:00:00',
        ]);

        DB::table('employee_details')->insert([
            'employee_id' => $employeeId,
            'cla_trab' => '000123',
            'puesto_id' => 123,
            'created_at' => '2026-05-25 18:00:00',
            'updated_at' => '2026-05-25 18:00:00',
        ]);

        DB::table('employee_import_metadata')->updateOrInsert(
            ['employee_id' => $employeeId],
            [
                'source' => 'employees_excel',
                'payload' => json_encode([
                    'fecha_ing' => '2024-01-15',
                    'fecha_baja' => '2026-06-30',
                ]),
                'created_at' => '2026-05-25 18:00:00',
                'updated_at' => '2026-05-25 18:00:00',
            ]
        );

        return $employeeId;
    }

    private function createEmployeeWithoutPosition(): int
    {
        $employeeId = $this->createEmployee(12016, 'EMPLEADO SIN PUESTO');

        DB::table('employee_details')->insert([
            'employee_id' => $employeeId,
            'cla_trab' => '000124',
            'puesto_id' => null,
            'created_at' => '2026-05-25 18:00:00',
            'updated_at' => '2026-05-25 18:00:00',
        ]);

        return $employeeId;
    }

    private function createEmployee(int $fortiaEmployeeId, string $fullName): int
    {
        return DB::table('employees')->insertGetId([
            'fortia_employee_id' => $fortiaEmployeeId,
            'employee_code' => '000123',
            'full_name' => $fullName,
            'name' => explode(' ', $fullName)[2] ?? 'DANIEL',
            'last_name' => explode(' ', $fullName)[0] ?? 'ANDRADE',
            'second_last_name' => explode(' ', $fullName)[1] ?? 'CRUZ',
            'status' => 'A',
            'company_id' => 1,
            'company_name' => 'Medical Life',
            'base_location_id' => 10,
            'base_location_name' => 'Corporativo Lago Xochimilco',
            'department_id' => 5,
            'department_name' => 'Operaciones',
            'can_check_all_branches' => true,
            'check_scope' => 'ANY_BRANCH',
            'has_fingerprint' => true,
            'has_face_enrollment' => false,
            'face_enabled' => false,
            'rfc' => 'AACD120315ABC',
            'curp' => 'AACD120315HDFNRL09',
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

        if (! Schema::hasTable('puestos')) {
            Schema::create('puestos', function (Blueprint $table): void {
                $table->id();
                $table->string('cla_puesto', 50)->unique();
                $table->string('nom_puesto', 255);
                $table->timestamps();
            });
            $this->createdPuestosTable = true;
        }

        if (! Schema::hasTable('employee_details')) {
            Schema::create('employee_details', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id')->unique();
                $table->string('cla_trab', 50)->nullable();
                $table->unsignedBigInteger('puesto_id')->nullable();
                $table->timestamps();
            });
            $this->createdEmployeeDetailsTable = true;
        }

        if (! Schema::hasTable('employee_import_metadata')) {
            Schema::create('employee_import_metadata', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id')->unique();
                $table->string('source', 40)->default('employees_excel');
                $table->json('payload')->nullable();
                $table->timestamps();
            });
            $this->createdEmployeeImportMetadataTable = true;
        }
    }

    private function cleanData(): void
    {
        if (Schema::hasTable('employee_details')) {
            DB::table('employee_details')->delete();
        }

        if (Schema::hasTable('puestos')) {
            DB::table('puestos')->delete();
        }

        if (Schema::hasTable('employee_import_metadata')) {
            DB::table('employee_import_metadata')->delete();
        }

        DB::table('employees')->delete();
    }
}
