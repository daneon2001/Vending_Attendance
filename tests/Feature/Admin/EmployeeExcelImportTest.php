<?php

namespace Tests\Feature\Admin;

use App\Actions\SyncPermissionCatalog;
use App\Models\Area;
use App\Models\CentroCosto;
use App\Models\Company;
use App\Models\Departamento;
use App\Models\Employee;
use App\Models\EmployeeStatusChange;
use App\Models\Location;
use App\Models\PeriodoPago;
use App\Models\Permission;
use App\Models\Puesto;
use App\Models\RazonSocial;
use App\Models\RegistroImss;
use App\Models\Role;
use App\Models\Ubicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class EmployeeExcelImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SyncPermissionCatalog::run();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_employees_page_exposes_import_permission_only_to_authorized_user(): void
    {
        $authorizedUser = $this->createUserWithPermissions([
            'employees' => ['view', 'import'],
        ]);

        $authorizedResponse = $this->actingAs($authorizedUser)
            ->get(route('employees.index'));

        $authorizedResponse->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Employees/EmployeesCatalog'));

        $authorizedPermissions = data_get($authorizedResponse->viewData('page'), 'props.auth.permissions.employees', []);
        $this->assertIsArray($authorizedPermissions);
        $this->assertContains('import', $authorizedPermissions);

        $unauthorizedUser = $this->createUserWithPermissions([
            'employees' => ['view'],
        ]);

        $unauthorizedResponse = $this->actingAs($unauthorizedUser)
            ->get(route('employees.index'));

        $unauthorizedResponse->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Employees/EmployeesCatalog'));

        $unauthorizedPermissions = data_get($unauthorizedResponse->viewData('page'), 'props.auth.permissions.employees', []);
        $this->assertIsArray($unauthorizedPermissions);
        $this->assertNotContains('import', $unauthorizedPermissions);
    }

    public function test_user_without_import_permission_cannot_preview_or_import_file(): void
    {
        $user = $this->createUserWithPermissions([
            'employees' => ['view'],
        ]);

        $file = $this->makeExcelUpload([
            'CLA_TRAB',
            'NOMBRE',
            'ESTATUS_TRABAJADOR',
        ], [
            ['1001', 'Empleado Sin Permiso', 'ACTIVO'],
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/api/admin/employees/import/catalogs/missing/create', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertForbidden();
    }

    public function test_preview_detects_rows_from_real_export_like_upload_with_tmp_storage_extension(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload(
            $this->headers(),
            [
                $this->employeeRow([
                    'CLA_TRAB' => '1',
                    'NOMBRE' => 'MONDRAGON HERNANDEZ GRISELDA',
                    'CURP' => 'HEMG801007MDFRNR04',
                    'RFC' => 'HEMG801007985',
                    'NUM_IMSS' => '18978023078   ',
                    'FECHA_ING' => '23/02/2022',
                    'ESTATUS_TRABAJADOR' => 'ACTIVO',
                ]),
                $this->employeeRow([
                    'CLA_TRAB' => '2',
                    'NOMBRE' => 'SEGUNDO EMPLEADO',
                    'ESTATUS_TRABAJADOR' => 'ACTIVO',
                ]),
            ],
            'Export (47).xlsx',
            'tmp',
            true
        );

        $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('can_import', true)
            ->assertJsonPath('summary.total_rows', 2)
            ->assertJsonPath('summary.new_records', 2)
            ->assertJsonPath('summary.error_records', 0)
            ->assertJsonPath('rows.0.row_number', 2)
            ->assertJsonPath('rows.1.row_number', 3);
    }

    public function test_row_with_cla_trab_and_nombre_is_considered_valid_even_when_other_columns_are_empty(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload(
            $this->headers(),
            [
                $this->employeeRow([
                    'CLA_TRAB' => '19001',
                    'NOMBRE' => 'EMPLEADO MINIMO',
                ]),
            ],
            'Export (47).xlsx',
            'tmp',
            true
        );

        $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('can_import', true)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.new_records', 1)
            ->assertJsonPath('rows.0.action', 'create');
    }

    public function test_can_import_new_employee_from_excel(): void
    {
        $user = $this->createAdminImporter();

        $row = [
            'CLA_TRAB' => '15001',
            'NOMBRE' => 'Trabajador Nuevo Demo',
            'CURP' => 'TUDO900101HDFRMR01',
            'RFC' => 'TUDO900101AB1',
            'NUM_IMSS' => '12345678901',
            'CLA_RAZON_SOCIAL' => '2001',
            'NOM_RAZON_SOCIAL' => 'Empresa Importada',
            'CLA_REG_IMSS' => 'REG-01',
            'NOM_REG_IMSS' => 'Registro Demo',
            'FECHA_ING' => '15/02/2026',
            'FECHA_ING_GRUPO' => '10/04/2019',
            'CLA_PUESTO' => 'PST-01',
            'NOM_PUESTO' => 'Operador',
            'CLA_CENTRO_COSTO' => 'CC-01',
            'NOM_CENTRO_COSTO' => 'Centro Demo',
            'CLA_AREA' => 'AR-01',
            'NOM_AREA' => 'Area Demo',
            'CLA_DEPTO' => 'DEP-01',
            'NOM_DEPARTAMENTO' => 'Departamento Demo',
            'CLA_UBICACION' => '3001',
            'NOM_UBICACION' => 'Sucursal Importada',
            'CLA_PERIODO_PAGO' => 'SEM',
            'NOM_PERIODO_PAGO' => 'Semanal',
            'ROLL_TURNO' => 'MATUTINO',
            'CORREO_CORPORATIVO' => 'nuevo@empresa.test',
            'CORREO_PERSONAL' => 'nuevo.personal@test.com',
            'ANTIGUEDAD' => '1.5',
            'SINDICALIZADO' => 'NO',
            'TIPO_DE_CONTRATO' => 'INDEFINIDO',
            'INICIO_CONTRATO' => '15/02/2026',
            'DIAS_DE_CONTRATO' => '365',
            'CALLE' => 'Av. Principal',
            'COLONIA' => 'Centro',
            'CODIGO_POSTAL' => '01234',
            'CIUDAD' => 'CDMX',
            'MUNICIPIO' => 'Cuauhtemoc',
            'NACIONALIDAD' => 'Mexicana',
            'PAIS_NACIMIENTO' => 'Mexico',
            'TELEFONO' => '5512345678',
            'FECHA_NACIMIENTO' => '01/01/1990',
            'GENERO' => 'MASCULINO',
            'CODIGO_POSTAL_FISCAL' => '01234',
            'ESTATUS_TRABAJADOR' => 'ACTIVO',
        ];

        $this->seedResolvedCatalogs($row);

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow($row),
        ]);

        $previewResponse = $this->actingAs($user)->post('/api/admin/employees/import/preview', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $previewResponse->assertOk()
            ->assertJsonPath('can_import', true)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.new_records', 1)
            ->assertJsonPath('summary.error_records', 0);

        $importResponse = $this->actingAs($user)->post('/api/admin/employees/import', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $importResponse->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('updated_count', 0)
            ->assertJsonPath('imported_count', 1);

        $employee = Employee::query()->where('fortia_employee_id', 15001)->firstOrFail();

        $this->assertSame('Trabajador Nuevo Demo', $employee->full_name);
        $this->assertSame('A', $employee->status);
        $this->assertSame('TUDO900101AB1', $employee->rfc);
        $this->assertSame('TUDO900101HDFRMR01', $employee->curp);
        $this->assertSame('12345678901', $employee->imss_number);
        $this->assertSame('nuevo@empresa.test', $employee->email_company);
        $this->assertSame(2001, (int) $employee->company_id);
        $this->assertSame(3001, (int) $employee->base_location_id);
        $this->assertSame('2019-04-10', $employee->hire_date?->toDateString());
        $this->assertNull($employee->termination_date);

        $this->assertDatabaseHas('employee_details', [
            'employee_id' => $employee->id,
            'cla_trab' => '15001',
        ]);

        $this->assertDatabaseHas('employee_import_metadata', [
            'employee_id' => $employee->id,
            'source' => 'employees_excel',
        ]);

        $metadata = json_decode((string) \Illuminate\Support\Facades\DB::table('employee_import_metadata')
            ->where('employee_id', $employee->id)
            ->value('payload'), true);

        $this->assertIsArray($metadata);
        $this->assertSame('2026-02-15', $metadata['fecha_ing'] ?? null);
        $this->assertSame('2019-04-10', $metadata['fecha_ing_grupo'] ?? null);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'employees.import_completed',
            'action' => 'employees.import',
            'entity' => 'employees',
        ]);
    }

    public function test_num_imss_is_trimmed_and_hire_date_uses_normalized_fecha_ing_grupo(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '15555',
                'NOMBRE' => 'EMPLEADO FECHA',
                'NUM_IMSS' => '  99988877766  ',
                'FECHA_ING' => '23/02/2022',
                'FECHA_ING_GRUPO' => '04/07/2018',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk();

        $employee = Employee::query()->where('fortia_employee_id', 15555)->firstOrFail();
        $this->assertSame('99988877766', $employee->imss_number);
        $this->assertSame('2018-07-04', $employee->hire_date?->toDateString());

        $metadata = json_decode((string) \Illuminate\Support\Facades\DB::table('employee_import_metadata')
            ->where('employee_id', $employee->id)
            ->value('payload'), true);

        $this->assertIsArray($metadata);
        $this->assertSame('2022-02-23', $metadata['fecha_ing'] ?? null);
        $this->assertSame('2018-07-04', $metadata['fecha_ing_grupo'] ?? null);
    }

    public function test_can_update_existing_employee_without_duplication_by_cla_trab(): void
    {
        $user = $this->createAdminImporter();

        $employee = Employee::query()->create([
            'fortia_employee_id' => 16001,
            'name' => 'Nombre Legacy',
            'full_name' => 'Nombre Legacy',
            'status' => 'B',
            'termination_date' => '2025-12-31',
            'rfc' => 'OLDR900101AB1',
            'curp' => 'OLDR900101HDFRMR01',
        ]);

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '16001',
                'NOMBRE' => 'Nombre Actualizado Importado',
                'CURP' => 'NUAI900101HDFRMR02',
                'RFC' => 'NUAI900101AB2',
                'NUM_IMSS' => '55555555555',
                'FECHA_ING' => '20/02/2026',
                'FECHA_ING_GRUPO' => '05/01/2017',
                'CORREO_CORPORATIVO' => 'actualizado@empresa.test',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1);

        $employee->refresh();

        $this->assertSame('Nombre Actualizado Importado', $employee->full_name);
        $this->assertSame('Nombre Legacy', $employee->name);
        $this->assertSame('NUAI900101AB2', $employee->rfc);
        $this->assertSame('NUAI900101HDFRMR02', $employee->curp);
        $this->assertSame('A', $employee->status);
        $this->assertSame('2017-01-05', $employee->hire_date?->toDateString());
        $this->assertNull($employee->termination_date);
        $this->assertSame(1, Employee::query()->where('fortia_employee_id', 16001)->count());
    }

    public function test_can_apply_termination_for_existing_employee_without_curp_rfc_or_catalog_data(): void
    {
        $user = $this->createAdminImporter();
        $company = Company::query()->create([
            'name' => 'Empresa Baja',
            'code' => 'EB',
            'status' => 1,
        ]);

        $employee = Employee::query()->create([
            'fortia_employee_id' => 26001,
            'company_id' => $company->id,
            'name' => 'Empleado',
            'full_name' => 'Empleado Vigente',
            'status' => 'A',
            'hire_date' => '2024-03-01',
            'rfc' => 'VIGE900101AB1',
            'curp' => 'VIGE900101HDFRMR01',
        ]);

        DB::table('employee_import_metadata')->insert([
            'employee_id' => $employee->id,
            'source' => 'employees_excel',
            'source_file_name' => 'alta-original.xlsx',
            'payload' => json_encode([
                'fecha_ing' => '2025-03-01',
                'fecha_ing_grupo' => '2024-03-01',
            ]),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '26001',
                'NOMBRE' => '',
                'CURP' => '',
                'RFC' => '',
                'NOM_UBICACION' => '',
                'NOM_PUESTO' => '',
                'NOM_DEPARTAMENTO' => '',
                'NOM_RAZON_SOCIAL' => '',
                'ESTATUS_TRABAJADOR' => 'BAJA',
                'FECHA_BAJA' => '19/06/2026',
                'CAUSA_BAJA' => 'Separacion',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('can_import', true)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.termination_applied_count', 1)
            ->assertJsonPath('summary.termination_skipped_not_found_count', 0)
            ->assertJsonPath('summary.error_records', 0)
            ->assertJsonPath('rows.0.action', 'update');

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('termination_applied_count', 1)
            ->assertJsonPath('termination_skipped_not_found_count', 0)
            ->assertJsonPath('summary.normal_updated_count', 0);

        $employee->refresh();
        $this->assertSame('B', $employee->status);
        $this->assertSame('2024-03-01', $employee->hire_date?->toDateString());
        $this->assertSame('2026-06-19', $employee->termination_date?->toDateString());

        $metadata = json_decode((string) DB::table('employee_import_metadata')
            ->where('employee_id', $employee->id)
            ->value('payload'), true);
        $this->assertSame('2025-03-01', $metadata['fecha_ing'] ?? null);
        $this->assertSame('2024-03-01', $metadata['fecha_ing_grupo'] ?? null);
        $this->assertSame('2026-06-19', $metadata['fecha_baja'] ?? null);

        $statusChange = EmployeeStatusChange::query()
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($statusChange);
        $this->assertSame('A', $statusChange->old_status);
        $this->assertSame('B', $statusChange->new_status);
        $this->assertSame('employees_excel', $statusChange->source);
        $this->assertSame('2026-06-19', data_get($statusChange->meta, 'remote_updated_at') ? substr((string) data_get($statusChange->meta, 'remote_updated_at'), 0, 10) : null);
    }

    public function test_termination_import_does_not_fail_when_existing_employee_has_no_company_for_status_history(): void
    {
        $user = $this->createAdminImporter();

        $employee = Employee::query()->create([
            'fortia_employee_id' => 26003,
            'company_id' => null,
            'name' => 'Empleado Sin Empresa',
            'full_name' => 'Empleado Sin Empresa',
            'status' => 'A',
        ]);

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '26003',
                'ESTATUS_TRABAJADOR' => 'BAJA',
                'FECHA_BAJA' => '21/06/2026',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('termination_applied_count', 1)
            ->assertJsonPath('termination_skipped_not_found_count', 0);

        $employee->refresh();

        $this->assertSame('B', $employee->status);
        $this->assertSame('2026-06-21', $employee->termination_date?->toDateString());
        $this->assertDatabaseCount('employee_status_changes', 0);
        $this->assertDatabaseHas('employee_import_metadata', [
            'employee_id' => $employee->id,
            'source' => 'employees_excel',
        ]);
    }

    public function test_termination_for_missing_employee_is_skipped_without_creating_record(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '26002',
                'ESTATUS_TRABAJADOR' => 'BAJA',
                'FECHA_BAJA' => '20/06/2026',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('can_import', true)
            ->assertJsonPath('summary.termination_applied_count', 0)
            ->assertJsonPath('summary.termination_skipped_not_found_count', 1)
            ->assertJsonPath('rows.0.action', 'skip')
            ->assertJsonPath('rows.0.warnings.0', 'Baja omitida: empleado no encontrado.');

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 0)
            ->assertJsonPath('termination_applied_count', 0)
            ->assertJsonPath('termination_skipped_not_found_count', 1)
            ->assertJsonPath('imported_count', 0);

        $this->assertDatabaseMissing('employees', [
            'fortia_employee_id' => 26002,
        ]);
        $this->assertDatabaseCount('employee_status_changes', 0);
    }

    public function test_termination_import_processes_multiple_batches_and_preserves_global_counters(): void
    {
        $user = $this->createAdminImporter();
        $company = Company::query()->create([
            'name' => 'Empresa Lotes',
            'code' => 'EL',
            'status' => 1,
        ]);

        $employee = Employee::query()->create([
            'fortia_employee_id' => 30501,
            'company_id' => $company->id,
            'name' => 'Empleado Batch',
            'full_name' => 'Empleado Batch',
            'status' => 'A',
        ]);

        $rows = [];

        for ($index = 1; $index <= 500; $index++) {
            $rows[] = $this->employeeRow([
                'CLA_TRAB' => (string) (40000 + $index),
                'ESTATUS_TRABAJADOR' => 'BAJA',
                'FECHA_BAJA' => '22/06/2026',
            ]);
        }

        $rows[] = $this->employeeRow([
            'CLA_TRAB' => '30501',
            'ESTATUS_TRABAJADOR' => 'BAJA',
            'FECHA_BAJA' => '22/06/2026',
        ]);

        $file = $this->makeExcelUpload($this->headers(), $rows);

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('imported_count', 1)
            ->assertJsonPath('termination_applied_count', 1)
            ->assertJsonPath('termination_skipped_not_found_count', 500)
            ->assertJsonPath('normal_created_count', 0)
            ->assertJsonPath('normal_updated_count', 0)
            ->assertJsonPath('summary.termination_applied_count', 1)
            ->assertJsonPath('summary.termination_skipped_not_found_count', 500);

        $employee->refresh();

        $this->assertSame('B', $employee->status);
    }

    public function test_invalid_file_is_rejected(): void
    {
        $user = $this->createAdminImporter();

        $invalidFile = UploadedFile::fake()->create('empleados.txt', 5, 'text/plain');

        $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $invalidFile,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_preview_detects_row_errors(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '17001',
                'NOMBRE' => '',
                'CURP' => 'CURPINVALIDA',
                'RFC' => 'RFCINVALIDO',
                'FECHA_ING' => '31/31/2026',
                'ESTATUS_TRABAJADOR' => 'SUSPENDIDO',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('can_import', false)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.error_records', 1)
            ->assertJsonPath('rows.0.action', 'error');
    }

    public function test_preview_detects_duplicate_cla_trab_inside_file(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '18001',
                'NOMBRE' => 'Empleado Uno',
                'CURP' => 'EUOD900101HDFRMR01',
                'RFC' => 'EUOD900101AB1',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
            $this->employeeRow([
                'CLA_TRAB' => '18001',
                'NOMBRE' => 'Empleado Dos',
                'CURP' => 'EDOD900101HDFRMR02',
                'RFC' => 'EDOD900101AB2',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('can_import', false)
            ->assertJsonPath('summary.error_records', 2);
    }

    public function test_preview_detects_and_groups_missing_catalogs_by_type(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '22001',
                'NOMBRE' => 'Empleado Catalogos 1',
                'CLA_DEPTO' => 'DEP-09',
                'NOM_DEPARTAMENTO' => 'Departamento Nueve',
                'CLA_PUESTO' => 'PST-09',
                'NOM_PUESTO' => 'Puesto Nueve',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
            $this->employeeRow([
                'CLA_TRAB' => '22002',
                'NOMBRE' => 'Empleado Catalogos 2',
                'CLA_DEPTO' => 'DEP-09',
                'NOM_DEPARTAMENTO' => 'Departamento Nueve',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->post('/api/admin/employees/import/preview', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertOk()
            ->assertJsonPath('can_import', false)
            ->assertJsonPath('summary.total_rows', 2)
            ->assertJsonPath('summary.error_records', 0)
            ->assertJsonPath('summary.employees_pending_by_catalogs', 2)
            ->assertJsonPath('summary.employees_resolvable_after_catalog_creation', 2)
            ->assertJsonPath('catalogs.missing_total', 2)
            ->assertJsonPath('catalogs.missing_types', 2)
            ->assertJsonPath('catalogs.creatable_total', 2)
            ->assertJsonPath('rows.0.blocked_by_catalogs', true)
            ->assertJsonPath('rows.0.can_be_resolved_by_catalog_creation', true);

        $payload = $response->json();
        $types = collect(data_get($payload, 'catalogs.by_type', []))->keyBy('type');

        $this->assertSame(1, (int) data_get($types, 'departamentos.missing_count'));
        $this->assertSame(2, (int) data_get($types, 'departamentos.affected_rows'));
        $this->assertSame(1, (int) data_get($types, 'puestos.missing_count'));
        $this->assertSame(1, (int) data_get($types, 'puestos.affected_rows'));

        $catalogItem = collect(data_get($payload, 'catalogs.items', []))
            ->firstWhere('type', 'departamentos');

        $this->assertNotNull($catalogItem);
        $this->assertSame('DEP-09', $catalogItem['code']);
        $this->assertSame([2, 3], $catalogItem['row_numbers']);
        $this->assertContains('Departamentos', data_get($payload, 'rows.0.catalog_missing_labels', []));
        $this->assertContains('Puestos', data_get($payload, 'rows.0.catalog_missing_labels', []));
    }

    public function test_create_missing_catalogs_does_not_duplicate_and_recalculates_preview(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '23001',
                'NOMBRE' => 'Empleado Crear Catalogos',
                'CLA_DEPTO' => 'DEP-11',
                'NOM_DEPARTAMENTO' => 'Departamento Once',
                'CLA_PUESTO' => 'PST-11',
                'NOM_PUESTO' => 'Puesto Once',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
            $this->employeeRow([
                'CLA_TRAB' => '23002',
                'NOMBRE' => 'Empleado Crear Catalogos 2',
                'CLA_DEPTO' => 'DEP-11',
                'NOM_DEPARTAMENTO' => 'Departamento Once',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->post('/api/admin/employees/import/catalogs/missing/create', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertOk()
            ->assertJsonPath('created_catalogs.departamentos', 1)
            ->assertJsonPath('created_catalogs.puestos', 1)
            ->assertJsonPath('preview.catalogs.missing_total', 0)
            ->assertJsonPath('preview.summary.employees_pending_by_catalogs', 0)
            ->assertJsonPath('preview.can_import', true);

        $this->assertDatabaseCount('departamentos', 1);
        $this->assertDatabaseCount('puestos', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'employees.import_catalogs_created',
            'action' => 'employees.import_catalogs_created',
            'entity' => 'employees',
        ]);
    }

    public function test_import_is_blocked_while_catalogs_are_missing(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '24001',
                'NOMBRE' => 'Empleado Bloqueado',
                'CLA_DEPTO' => 'DEP-77',
                'NOM_DEPARTAMENTO' => 'Departamento Bloqueado',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonPath('can_import', false)
            ->assertJsonPath('summary.employees_pending_by_catalogs', 1);

        $this->assertDatabaseMissing('employees', [
            'fortia_employee_id' => 24001,
        ]);
    }

    public function test_after_creating_catalogs_employee_can_be_imported(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '25001',
                'NOMBRE' => 'Empleado Resuelto',
                'CLA_DEPTO' => 'DEP-55',
                'NOM_DEPARTAMENTO' => 'Departamento Resuelto',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
        ]);

        $this->actingAs($user)
            ->post('/api/admin/employees/import/catalogs/missing/create', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('preview.can_import', true);

        $this->actingAs($user)
            ->post('/api/admin/employees/import', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 1);

        $employee = Employee::query()->where('fortia_employee_id', 25001)->firstOrFail();
        $this->assertDatabaseHas('employee_details', [
            'employee_id' => $employee->id,
            'cla_trab' => '25001',
            'departamento_id' => Departamento::query()->where('cla_depto', 'DEP-55')->value('id'),
        ]);
    }

    public function test_create_missing_operational_company_and_location_catalogs_links_location_to_company(): void
    {
        $user = $this->createAdminImporter();

        $file = $this->makeExcelUpload($this->headers(), [
            $this->employeeRow([
                'CLA_TRAB' => '26001',
                'NOMBRE' => 'Empleado Operativo',
                'CLA_RAZON_SOCIAL' => '9100',
                'NOM_RAZON_SOCIAL' => 'Empresa Operativa Nueva',
                'CLA_UBICACION' => '8100',
                'NOM_UBICACION' => 'Ubicacion Operativa Nueva',
                'ESTATUS_TRABAJADOR' => 'ACTIVO',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->post('/api/admin/employees/import/catalogs/missing/create', [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertOk()
            ->assertJsonPath('created_catalogs.companies', 1)
            ->assertJsonPath('created_catalogs.locations', 1)
            ->assertJsonPath('created_catalogs.razones_sociales', 1)
            ->assertJsonPath('created_catalogs.ubicaciones_laborales', 1)
            ->assertJsonPath('preview.catalogs.missing_total', 0)
            ->assertJsonPath('preview.can_import', true);

        $company = Company::query()->where('fortia_company_id', 9100)->firstOrFail();
        $location = Location::query()->where('fortia_location_id', 8100)->firstOrFail();

        $this->assertSame($company->id, $location->company_id);
    }

    private function createAdminImporter(): User
    {
        return $this->createUserWithPermissions([
            'employees' => ['view', 'import'],
        ], 'Administrador');
    }

    /**
     * @param  array<string, array<int, string>>  $definitions
     */
    private function createUserWithPermissions(array $definitions, string $roleName = 'Role Import'): User
    {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => $roleName.' '.uniqid(),
            'description' => 'Role for employee import test',
            'is_system' => false,
        ]);

        if ($roleName === 'Administrador') {
            $role->update(['name' => 'Administrador']);
        }

        $permissionIds = collect($definitions)
            ->flatMap(function (array $actions, string $module) {
                return Permission::query()
                    ->where('module', $module)
                    ->whereIn('action', $actions)
                    ->pluck('id');
            })
            ->unique()
            ->values()
            ->all();

        $role->permissions()->sync($permissionIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh('roles.permissions');
    }

    /**
     * @param  array<string, string>  $row
     */
    private function seedResolvedCatalogs(array $row): void
    {
        $company = null;
        $companyCode = trim((string) ($row['CLA_RAZON_SOCIAL'] ?? ''));
        $companyName = trim((string) ($row['NOM_RAZON_SOCIAL'] ?? ''));

        if ($companyCode !== '' || $companyName !== '') {
            $company = Company::query()->firstOrCreate(
                ['fortia_company_id' => (int) $companyCode],
                [
                    'name' => $companyName !== '' ? $companyName : "Sin nombre - {$companyCode}",
                    'code' => mb_substr($companyCode, 0, 20),
                    'status' => 1,
                ]
            );

            RazonSocial::query()->firstOrCreate(
                ['cla_razon_social' => $companyCode],
                ['nom_razon_social' => $companyName !== '' ? $companyName : "Sin nombre - {$companyCode}"]
            );
        }

        $locationCode = trim((string) ($row['CLA_UBICACION'] ?? ''));
        $locationName = trim((string) ($row['NOM_UBICACION'] ?? ''));
        if ($locationCode !== '' || $locationName !== '') {
            Location::query()->firstOrCreate(
                ['fortia_location_id' => (int) $locationCode],
                [
                    'company_id' => $company?->id,
                    'name' => $locationName !== '' ? $locationName : "Sin nombre - {$locationCode}",
                    'code' => mb_substr($locationCode, 0, 30),
                    'status' => 1,
                ]
            );

            Ubicacion::query()->firstOrCreate(
                ['cla_ubicacion' => $locationCode],
                ['nom_ubicacion' => $locationName !== '' ? $locationName : "Sin nombre - {$locationCode}"]
            );
        }

        $this->firstOrCreateSimpleCatalog($row, 'CLA_REG_IMSS', 'NOM_REG_IMSS', RegistroImss::class, 'cla_reg_imss', 'nom_reg_imss');
        $this->firstOrCreateSimpleCatalog($row, 'CLA_PUESTO', 'NOM_PUESTO', Puesto::class, 'cla_puesto', 'nom_puesto');
        $this->firstOrCreateSimpleCatalog($row, 'CLA_CENTRO_COSTO', 'NOM_CENTRO_COSTO', CentroCosto::class, 'cla_centro_costo', 'nom_centro_costo');
        $this->firstOrCreateSimpleCatalog($row, 'CLA_AREA', 'NOM_AREA', Area::class, 'cla_area', 'nom_area');
        $this->firstOrCreateSimpleCatalog($row, 'CLA_DEPTO', 'NOM_DEPARTAMENTO', Departamento::class, 'cla_depto', 'nom_departamento');
        $this->firstOrCreateSimpleCatalog($row, 'CLA_PERIODO_PAGO', 'NOM_PERIODO_PAGO', PeriodoPago::class, 'cla_periodo_pago', 'nom_periodo_pago');
    }

    /**
     * @param  array<string, string>  $row
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    private function firstOrCreateSimpleCatalog(
        array $row,
        string $codeKey,
        string $nameKey,
        string $modelClass,
        string $modelCodeColumn,
        string $modelNameColumn
    ): void {
        $code = trim((string) ($row[$codeKey] ?? ''));
        $name = trim((string) ($row[$nameKey] ?? ''));

        if ($code === '' && $name === '') {
            return;
        }

        $modelClass::query()->firstOrCreate(
            [$modelCodeColumn => $code],
            [$modelNameColumn => $name !== '' ? $name : "Sin nombre - {$code}"]
        );
    }

    /**
     * @return array<int, string>
     */
    private function headers(): array
    {
        return [
            'CLA_TRAB',
            'NOMBRE',
            'CURP',
            'RFC',
            'NUM_IMSS',
            'CLA_RAZON_SOCIAL',
            'NOM_RAZON_SOCIAL',
            'CLA_REG_IMSS',
            'NOM_REG_IMSS',
            'FECHA_ING',
            'FECHA_ING_GRUPO',
            'CLA_PUESTO',
            'NOM_PUESTO',
            'CLA_CENTRO_COSTO',
            'NOM_CENTRO_COSTO',
            'CLA_AREA',
            'NOM_AREA',
            'CLA_DEPTO',
            'NOM_DEPARTAMENTO',
            'CLA_UBICACION',
            'NOM_UBICACION',
            'CLA_PERIODO_PAGO',
            'NOM_PERIODO_PAGO',
            'ROLL_TURNO',
            'CORREO_CORPORATIVO',
            'CORREO_PERSONAL',
            'ANTIGUEDAD',
            'SINDICALIZADO',
            'TIPO_DE_CONTRATO',
            'INICIO_CONTRATO',
            'DIAS_DE_CONTRATO',
            'CALLE',
            'COLONIA',
            'CODIGO_POSTAL',
            'CIUDAD',
            'MUNICIPIO',
            'NACIONALIDAD',
            'PAIS_NACIMIENTO',
            'TELEFONO',
            'FECHA_NACIMIENTO',
            'GENERO',
            'CODIGO_POSTAL_FISCAL',
            'ESTATUS_TRABAJADOR',
            'FECHA_BAJA',
            'CAUSA_BAJA',
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<int, string>
     */
    private function employeeRow(array $overrides): array
    {
        $row = [];

        foreach ($this->headers() as $header) {
            $row[] = $overrides[$header] ?? '';
        }

        return $row;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function makeExcelUpload(
        array $headers,
        array $rows,
        string $fileName = 'employees.xlsx',
        string $storageExtension = 'xlsx',
        bool $sparseRows = false
    ): UploadedFile
    {
        $basePath = tempnam(sys_get_temp_dir(), 'employees-import-');
        if ($basePath === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal.');
        }

        @unlink($basePath);
        $path = $basePath.'.'.$storageExtension;
        $this->temporaryFiles[] = $path;

        $table = array_merge([$headers], $rows);
        $sharedStringMap = [];
        $sharedStrings = [];

        foreach ($table as $rowIndex => $row) {
            foreach ($row as $value) {
                if ($sparseRows && $rowIndex > 0 && ($value === null || $value === '')) {
                    continue;
                }

                $stringValue = (string) ($value ?? '');
                if (! array_key_exists($stringValue, $sharedStringMap)) {
                    $sharedStringMap[$stringValue] = count($sharedStrings);
                    $sharedStrings[] = $stringValue;
                }
            }
        }

        $sheetRowsXml = '';
        foreach ($table as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $cellsXml = '';

            foreach ($row as $columnIndex => $value) {
                if ($sparseRows && $rowIndex > 0 && ($value === null || $value === '')) {
                    continue;
                }

                $columnRef = $this->columnLetter($columnIndex).$rowNumber;
                $sharedIndex = $sharedStringMap[(string) ($value ?? '')];
                $cellsXml .= "<c r=\"{$columnRef}\" t=\"s\"><v>{$sharedIndex}</v></c>";
            }

            $sheetRowsXml .= "<row r=\"{$rowNumber}\">{$cellsXml}</row>";
        }

        $sharedStringsXml = '';
        foreach ($sharedStrings as $value) {
            $escapedValue = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $sharedStringsXml .= "<si><t>{$escapedValue}</t></si>";
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new \RuntimeException('No se pudo construir el archivo xlsx de prueba.');
        }

        $zip->addFromString('[Content_Types].xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>
XML);

        $zip->addFromString('_rels/.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);

        $zip->addFromString('xl/workbook.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML);

        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>
XML);

        $zip->addFromString('xl/worksheets/sheet1.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        {$sheetRowsXml}
    </sheetData>
</worksheet>
XML);

        $zip->addFromString('xl/sharedStrings.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="{$this->sharedStringCount($table)}" uniqueCount="{$this->sharedStringUniqueCount($sharedStrings)}">
    {$sharedStringsXml}
</sst>
XML);

        $zip->close();

        return new UploadedFile(
            $path,
            $fileName,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    /**
     * @param  array<int, array<int, string|null>>  $table
     */
    private function sharedStringCount(array $table): int
    {
        return array_sum(array_map('count', $table));
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function sharedStringUniqueCount(array $sharedStrings): int
    {
        return count($sharedStrings);
    }

    private function columnLetter(int $index): string
    {
        $index++;
        $letters = '';

        while ($index > 0) {
            $modulo = ($index - 1) % 26;
            $letters = chr(65 + $modulo).$letters;
            $index = (int) floor(($index - $modulo - 1) / 26);
        }

        return $letters;
    }
}
