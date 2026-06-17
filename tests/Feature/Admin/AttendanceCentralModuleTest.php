<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePermission;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceCentralModuleTest extends TestCase
{
    private bool $createdUsersTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdAttendanceLogsTable = false;
    private bool $createdAttendanceChangesTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnsurePermission::class);
        $this->ensureSchema();
        $this->cleanData();

        $userId = DB::table('users')->insertGetId([
            'name' => 'Attendance Admin',
            'email' => 'attendance-admin@example.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::query()->findOrFail($userId));
    }

    protected function tearDown(): void
    {
        if ($this->createdAttendanceChangesTable && Schema::hasTable('attendance_changes')) {
            Schema::drop('attendance_changes');
        }
        if ($this->createdAttendanceLogsTable && Schema::hasTable('attendance_logs')) {
            Schema::drop('attendance_logs');
        }
        if ($this->createdClocksTable && Schema::hasTable('clocks')) {
            Schema::drop('clocks');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdUsersTable && Schema::hasTable('users')) {
            Schema::drop('users');
        }

        parent::tearDown();
    }

    public function test_index_defaults_to_grouped_view_by_employee_and_day(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            [
                'log_id' => 1001,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-02-10 08:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 1002,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-02-15 18:00:00',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('admin.asistencias.index', [
            'from' => '2026-02-10',
            'to' => '2026-02-10',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/Index')
            ->where('viewMode', 'grouped')
            ->where('initialRecords.meta.total', 1)
            ->where('initialRecords.data.0.employee_id', $employeeId)
            ->where('initialRecords.data.0.local_date', '2026-02-10')
            ->where('initialRecords.data.0.total_checks', 1)
        );
    }

    public function test_grouped_view_calculates_first_and_last_check_correctly(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            [
                'log_id' => 2401,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 14:30:20',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2402,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 23:15:40',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('admin.asistencias.index', [
            'from' => '2026-04-26',
            'to' => '2026-04-26',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/Index')
            ->where('initialRecords.data.0.total_checks', 2)
            ->where('initialRecords.data.0.first_check_display', '2026-04-26 14:30:20')
            ->where('initialRecords.data.0.last_check_display', '2026-04-26 23:15:40')
            ->where('initialRecords.data.0.entry_count', 1)
            ->where('initialRecords.data.0.exit_count', 1)
        );
    }

    public function test_raw_view_displays_local_time_from_utc_using_location_timezone(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            'log_id' => 2403,
            'company_id' => 1,
            'employee_id' => $employeeId,
            'fortia_employee_id' => 88001,
            'location_id' => $locationId,
            'device_id' => $clockId,
            'log_date' => '2026-04-26 22:30:20',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'raw_payload' => json_encode([
                'punched_at_utc' => '2026-04-26 22:30:20',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('admin.asistencias.index', [
            'from' => '2026-04-26',
            'to' => '2026-04-26',
            'view_mode' => 'raw',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/Index')
            ->where('viewMode', 'raw')
            ->where('initialRecords.data.0.log_id', 2403)
            ->where('initialRecords.data.0.log_date_display', '2026-04-26 16:30:20')
            ->where('initialRecords.data.0.log_date_timezone', 'America/Mexico_City')
            ->where('initialRecords.data.0.log_date_utc_display', '2026-04-26 22:30:20')
        );
    }

    public function test_grouped_detail_returns_all_employee_checks_for_day(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            [
                'log_id' => 2510,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 14:30:20',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'adjustment_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2511,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 20:45:20',
                'log_type' => 2,
                'source' => 'manual',
                'attendance_status' => 'corregida',
                'adjustment_reason' => 'Salida registrada manualmente',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson(route('admin.asistencias.grouped-detail', [
            'from' => '2026-04-26',
            'to' => '2026-04-26',
            'employee_id' => $employeeId,
            'local_date' => '2026-04-26',
        ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total_checks', 2)
            ->assertJsonPath('data.records.0.log_id', 2510)
            ->assertJsonPath('data.records.1.log_id', 2511);
    }

    public function test_grouped_export_respects_visible_columns(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            [
                'log_id' => 2601,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 14:30:20',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2602,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 22:30:20',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('admin.asistencias.export', [
            'format' => 'csv',
            'from' => '2026-04-26',
            'to' => '2026-04-26',
            'view_mode' => 'grouped',
            'columns' => ['empleado', 'fecha_local', 'primera_checada', 'ultima_checada', 'total_checadas'],
        ]));

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString('Empleado,"Hora local / Fecha local","Primera checada"', $content);
        $this->assertStringContainsString('"Total checadas"', $content);
        $this->assertStringContainsString('"Empleado Demo",2026-04-26,"2026-04-26 14:30:20","2026-04-26 22:30:20",2', $content);
        $this->assertStringNotContainsString('Acciones', $content);
        $this->assertStringNotContainsString('Fuente', $content);
    }

    public function test_raw_export_includes_local_timezone_and_utc_columns(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            'log_id' => 2404,
            'company_id' => 1,
            'employee_id' => $employeeId,
            'fortia_employee_id' => 88001,
            'location_id' => $locationId,
            'device_id' => $clockId,
            'log_date' => '2026-04-26 22:30:20',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'raw_payload' => json_encode([
                'punched_at_utc' => '2026-04-26 22:30:20',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('admin.asistencias.export', [
            'format' => 'csv',
            'from' => '2026-04-26',
            'to' => '2026-04-26',
            'view_mode' => 'raw',
            'columns' => ['hora_local', 'fecha_utc', 'empleado', 'unidad', 'reloj', 'tipo', 'fuente', 'status', 'observaciones'],
        ]));

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString('"Hora local","Fecha UTC",Empleado,Unidad,Reloj,Tipo,Fuente,Status,Observaciones', $content);
        $this->assertStringContainsString('"2026-04-26 16:30:20","2026-04-26 22:30:20","Empleado Demo",Matriz,"Clock Main",IN,API,Valida,', $content);
    }

    public function test_full_checks_export_respects_filters_and_requested_columns(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        $secondaryClockId = DB::table('clocks')->insertGetId([
            'clock_name' => 'Clock Side',
            'serial_number' => 'CLK-002',
            'location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attendance_logs')->insert([
            [
                'log_id' => 2701,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 08:15:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'adjustment_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2702,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $secondaryClockId,
                'log_date' => '2026-04-26 18:45:00',
                'log_type' => 2,
                'source' => 'manual',
                'attendance_status' => 'corregida',
                'adjustment_reason' => 'No debe salir en este filtro',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('admin.asistencias.export-checks', [
            'format' => 'csv',
            'scope' => 'filtered',
            'date_from' => '2026-04-26',
            'date_to' => '2026-04-26',
            'clock_id' => $clockId,
            'status' => 'valida',
            'columns' => ['empleado', 'fecha_local', 'hora_local', 'reloj', 'status', 'columna_invalida'],
        ]));

        $response->assertOk();

        [$heading, $rows] = $this->parseExportCsvDataSection($response->streamedContent());

        $this->assertSame(['Empleado', 'Fecha local', 'Hora local', 'Reloj', 'Status'], $heading);
        $this->assertCount(1, $rows);
        $this->assertSame(['Empleado Demo', '2026-04-26', '08:15:00', 'Clock Main', 'Valida'], $rows[0]);
    }

    public function test_full_checks_employee_day_scope_returns_individual_rows_for_local_day(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        $otherEmployeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 88002,
            'company_id' => 1,
            'base_location_id' => $locationId,
            'full_name' => 'Empleado Alterno',
            'name' => 'Alterno',
            'status' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attendance_logs')->insert([
            [
                'log_id' => 2801,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 23:30:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'raw_payload' => json_encode([
                    'punched_at_utc' => '2026-04-26 23:30:00',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2802,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-27 02:15:00',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
                'raw_payload' => json_encode([
                    'punched_at_utc' => '2026-04-27 02:15:00',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2803,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-27 07:30:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'raw_payload' => json_encode([
                    'punched_at_utc' => '2026-04-27 07:30:00',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2804,
                'company_id' => 1,
                'employee_id' => $otherEmployeeId,
                'fortia_employee_id' => 88002,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-27 01:45:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'raw_payload' => json_encode([
                    'punched_at_utc' => '2026-04-27 01:45:00',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('admin.asistencias.export-checks', [
            'format' => 'csv',
            'scope' => 'employee_day',
            'from' => '2026-04-26',
            'to' => '2026-04-27',
            'employee_id' => $employeeId,
            'local_date' => '2026-04-26',
            'columns' => ['empleado', 'fecha_local', 'hora_local', 'tipo', 'status'],
        ]));

        $response->assertOk();

        [$heading, $rows] = $this->parseExportCsvDataSection($response->streamedContent());

        $this->assertSame(['Empleado', 'Fecha local', 'Hora local', 'Tipo', 'Status'], $heading);
        $this->assertCount(2, $rows);
        $this->assertSame(['Empleado Demo', '2026-04-26', '17:30:00', 'IN', 'Valida'], $rows[0]);
        $this->assertSame(['Empleado Demo', '2026-04-26', '20:15:00', 'OUT', 'Valida'], $rows[1]);
    }

    public function test_full_checks_export_is_not_limited_by_current_page(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            [
                'log_id' => 2901,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 08:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2902,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 12:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 2903,
                'company_id' => 1,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => $clockId,
                'log_date' => '2026-04-26 18:00:00',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('admin.asistencias.export-checks', [
            'format' => 'csv',
            'scope' => 'filtered',
            'from' => '2026-04-26',
            'to' => '2026-04-26',
            'per_page' => 10,
            'page' => 1,
            'columns' => ['hora_local', 'tipo'],
        ]));

        $response->assertOk();

        [, $rows] = $this->parseExportCsvDataSection($response->streamedContent());

        $this->assertCount(3, $rows);
        $this->assertSame(['08:00:00', 'IN'], $rows[0]);
        $this->assertSame(['12:00:00', 'IN'], $rows[1]);
        $this->assertSame(['18:00:00', 'OUT'], $rows[2]);
    }

    public function test_manual_adjustment_creates_audit_record(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        $response = $this->post(route('admin.asistencias.adjustments.store'), [
            'employee_id' => $employeeId,
            'location_id' => $locationId,
            'device_id' => $clockId,
            'log_date' => '2026-02-12 09:15:00',
            'log_type' => 'in',
            'reason' => 'Ajuste por falla de reloj',
            'notes' => 'Registro no capturado en dispositivo',
        ]);

        $response->assertRedirect();

        $recordId = DB::table('attendance_logs')
            ->where('employee_id', $employeeId)
            ->where('source', 'manual')
            ->where('attendance_status', 'corregida')
            ->value('id');

        $this->assertNotNull($recordId);

        $this->assertDatabaseHas('attendance_changes', [
            'attendance_log_id' => $recordId,
            'action' => 'manual_adjustment',
            'reason' => 'Ajuste por falla de reloj',
        ]);
    }

    public function test_annul_action_updates_status_and_creates_audit(): void
    {
        [$employeeId, $locationId, $clockId] = $this->createBaseReferences();

        $recordId = DB::table('attendance_logs')->insertGetId([
            'log_id' => 1900,
            'company_id' => 1,
            'employee_id' => $employeeId,
            'fortia_employee_id' => 88001,
            'location_id' => $locationId,
            'device_id' => $clockId,
            'log_date' => '2026-02-16 08:02:00',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->patch(route('admin.asistencias.annul', ['attendance_record' => $recordId]), [
            'reason' => 'Registro duplicado por reintento de sync',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('attendance_logs', [
            'id' => $recordId,
            'attendance_status' => 'anulada',
        ]);

        $this->assertDatabaseHas('attendance_changes', [
            'attendance_log_id' => $recordId,
            'action' => 'annulled',
            'reason' => 'Registro duplicado por reintento de sync',
        ]);
    }

    private function createBaseReferences(): array
    {
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Matriz',
            'code' => 'MAT',
            'timezone' => 'America/Mexico_City',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clockId = DB::table('clocks')->insertGetId([
            'clock_name' => 'Clock Main',
            'serial_number' => 'CLK-001',
            'location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 88001,
            'company_id' => 1,
            'base_location_id' => $locationId,
            'full_name' => 'Empleado Demo',
            'name' => 'Empleado',
            'status' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$employeeId, $locationId, $clockId];
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->timestamps();
            });
            $this->createdUsersTable = true;
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('full_name')->nullable();
                $table->string('name')->nullable();
                $table->string('status', 20)->default('A');
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('timezone', 64)->nullable();
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
        }
        if (! Schema::hasColumn('locations', 'code')) {
            Schema::table('locations', function (Blueprint $table): void {
                $table->string('code')->nullable()->after('name');
            });
        }
        if (! Schema::hasColumn('locations', 'timezone')) {
            Schema::table('locations', function (Blueprint $table): void {
                $table->string('timezone', 64)->nullable()->after('code');
            });
        }

        if (! Schema::hasTable('clocks')) {
            Schema::create('clocks', function (Blueprint $table): void {
                $table->id();
                $table->string('clock_name');
                $table->string('serial_number')->nullable();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->timestamps();
            });
            $this->createdClocksTable = true;
        }
        if (! Schema::hasColumn('clocks', 'serial_number')) {
            Schema::table('clocks', function (Blueprint $table): void {
                $table->string('serial_number')->nullable()->after('clock_name');
            });
        }

        if (! Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('log_id');
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('fortia_employee_id')->nullable();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('local_id', 100)->nullable();
                $table->dateTime('log_date');
                $table->tinyInteger('log_type')->default(0);
                $table->string('source', 20)->default('sync');
                $table->string('attendance_status', 20)->default('valida');
                $table->string('adjustment_reason', 500)->nullable();
                $table->dateTime('annulled_at')->nullable();
                $table->unsignedBigInteger('annulled_by_user_id')->nullable();
                $table->integer('status')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamps();
            });
            $this->createdAttendanceLogsTable = true;
        }
        if (! Schema::hasColumn('attendance_logs', 'source')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->string('source', 20)->default('sync')->after('log_type');
            });
        }
        if (! Schema::hasColumn('attendance_logs', 'attendance_status')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->string('attendance_status', 20)->default('valida')->after('source');
            });
        }
        if (! Schema::hasColumn('attendance_logs', 'adjustment_reason')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->string('adjustment_reason', 500)->nullable()->after('attendance_status');
            });
        }
        if (! Schema::hasColumn('attendance_logs', 'annulled_at')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->dateTime('annulled_at')->nullable()->after('adjustment_reason');
            });
        }
        if (! Schema::hasColumn('attendance_logs', 'annulled_by_user_id')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->unsignedBigInteger('annulled_by_user_id')->nullable()->after('annulled_at');
            });
        }

        if (! Schema::hasTable('attendance_changes')) {
            Schema::create('attendance_changes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('attendance_log_id')->nullable();
                $table->string('action', 40);
                $table->string('reason', 500)->nullable();
                $table->unsignedBigInteger('changed_by_user_id')->nullable();
                $table->string('changed_by_name')->nullable();
                $table->string('changed_by_email')->nullable();
                $table->json('before_data')->nullable();
                $table->json('after_data')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();
            });
            $this->createdAttendanceChangesTable = true;
        }
        if (! Schema::hasColumn('attendance_changes', 'action')) {
            Schema::table('attendance_changes', function (Blueprint $table): void {
                $table->string('action', 40)->nullable();
            });
        }
        if (! Schema::hasColumn('attendance_changes', 'reason')) {
            Schema::table('attendance_changes', function (Blueprint $table): void {
                $table->string('reason', 500)->nullable();
            });
        }
        if (! Schema::hasColumn('attendance_changes', 'before_data')) {
            Schema::table('attendance_changes', function (Blueprint $table): void {
                $table->json('before_data')->nullable();
            });
        }
        if (! Schema::hasColumn('attendance_changes', 'after_data')) {
            Schema::table('attendance_changes', function (Blueprint $table): void {
                $table->json('after_data')->nullable();
            });
        }
    }

    private function cleanData(): void
    {
        DB::table('attendance_changes')->delete();
        DB::table('attendance_logs')->delete();
        DB::table('employees')->delete();
        DB::table('clocks')->delete();
        DB::table('locations')->delete();
        DB::table('users')->delete();
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function parseExportCsvDataSection(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $dataLines = [];
        $dataSectionStarted = false;

        foreach ($lines as $line) {
            if (! $dataSectionStarted) {
                if (trim($line) === '') {
                    $dataSectionStarted = true;
                }

                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $dataLines[] = str_getcsv($line);
        }

        $heading = array_shift($dataLines) ?? [];

        if (isset($heading[0])) {
            $heading[0] = preg_replace('/^\xEF\xBB\xBF/', '', $heading[0]) ?? $heading[0];
        }

        return [$heading, $dataLines];
    }
}
