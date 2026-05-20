<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePermission;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceCardModuleTest extends TestCase
{
    private bool $createdUsersTable = false;

    private bool $createdCompaniesTable = false;

    private bool $createdLocationsTable = false;

    private bool $createdEmployeesTable = false;

    private bool $createdAttendanceLogsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(EnsurePermission::class);
        $this->ensureSchema();
        $this->cleanData();

        $userId = DB::table('users')->insertGetId([
            'name' => 'RH Admin',
            'email' => 'rh-admin@example.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::query()->findOrFail($userId));
    }

    protected function tearDown(): void
    {
        if ($this->createdAttendanceLogsTable && Schema::hasTable('attendance_logs')) {
            Schema::drop('attendance_logs');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }
        if ($this->createdCompaniesTable && Schema::hasTable('companies')) {
            Schema::drop('companies');
        }
        if ($this->createdUsersTable && Schema::hasTable('users')) {
            Schema::drop('users');
        }

        parent::tearDown();
    }

    public function test_index_renders_employee_card_summary_and_daily_statuses(): void
    {
        [$companyId, $locationId, $employeeId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            [
                'log_id' => 1001,
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => null,
                'log_date' => '2026-05-19 15:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'raw_payload' => json_encode([
                    'punched_at_utc' => '2026-05-19 15:00:00',
                    'source' => 'face',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 1002,
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => null,
                'log_date' => '2026-05-20 00:00:00',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
                'raw_payload' => json_encode([
                    'punched_at_utc' => '2026-05-20 00:00:00',
                    'source' => 'face',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_id' => 1003,
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'fortia_employee_id' => 88001,
                'location_id' => $locationId,
                'device_id' => null,
                'log_date' => '2026-05-20 15:05:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'raw_payload' => json_encode([
                    'punched_at_utc' => '2026-05-20 15:05:00',
                    'source' => 'fingerprint',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('attendance-cards.index', [
            'company_id' => $companyId,
            'location_id' => $locationId,
            'employee_id' => $employeeId,
            'period' => 'custom',
            'from_date' => '2026-05-19',
            'to_date' => '2026-05-21',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('AttendanceCards/Index')
            ->where('card.employee.name', 'Empleado Demo')
            ->where('card.summary.days_attended', 2)
            ->where('card.summary.absences', 1)
            ->where('card.summary.incomplete_days', 1)
            ->where('card.rows.0.status_label', 'Asistencia')
            ->where('card.rows.0.entry_display', '09:00 am')
            ->where('card.rows.0.exit_display', '06:00 pm')
            ->where('card.rows.1.status_label', 'Incompleta')
            ->where('card.rows.1.method_label', 'Huella')
            ->where('card.rows.2.status_label', 'Falta')
        );
    }

    public function test_export_returns_xlsx_file_for_selected_employee(): void
    {
        [$companyId, $locationId, $employeeId] = $this->createBaseReferences();

        DB::table('attendance_logs')->insert([
            'log_id' => 1101,
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'fortia_employee_id' => 88001,
            'location_id' => $locationId,
            'device_id' => null,
            'log_date' => '2026-05-19 15:00:00',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'raw_payload' => json_encode([
                'punched_at_utc' => '2026-05-19 15:00:00',
                'source' => 'face',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('attendance-cards.export', [
            'company_id' => $companyId,
            'location_id' => $locationId,
            'employee_id' => $employeeId,
            'period' => 'custom',
            'from_date' => '2026-05-19',
            'to_date' => '2026-05-19',
        ]));

        $response->assertOk();
        $response->assertDownload('Tarjeta_Asistencia_Empleado_Demo_Rango_personalizado.xlsx');
    }

    private function createBaseReferences(): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Medical Life',
            'code' => 'ML',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = DB::table('locations')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Corporativo Lago Xochimilco',
            'code' => 'LAGO',
            'timezone' => 'America/Mexico_City',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 88001,
            'company_id' => $companyId,
            'company_name' => 'Medical Life',
            'base_location_id' => $locationId,
            'base_location_name' => 'Corporativo Lago Xochimilco',
            'department_id' => 10,
            'department_name' => 'Operaciones',
            'full_name' => 'Empleado Demo',
            'name' => 'Empleado Demo',
            'status' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$companyId, $locationId, $employeeId];
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

        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable();
                $table->timestamps();
            });
            $this->createdCompaniesTable = true;
        }

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('timezone', 64)->nullable();
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('company_name')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('base_location_name')->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->string('department_name')->nullable();
                $table->string('full_name')->nullable();
                $table->string('name')->nullable();
                $table->string('status', 20)->default('A');
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
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
                $table->dateTime('log_date');
                $table->tinyInteger('log_type')->default(0);
                $table->string('source', 20)->default('sync');
                $table->string('attendance_status', 20)->default('valida');
                $table->json('raw_payload')->nullable();
                $table->timestamps();
            });
            $this->createdAttendanceLogsTable = true;
        }
    }

    private function cleanData(): void
    {
        DB::table('attendance_logs')->delete();
        DB::table('employees')->delete();
        DB::table('locations')->delete();
        DB::table('companies')->delete();
        DB::table('users')->delete();
    }
}
