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

    public function test_index_filters_records_by_date_range(): void
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
            ->where('initialRecords.meta.total', 1)
            ->where('initialRecords.data.0.log_id', 1001)
        );
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clockId = DB::table('clocks')->insertGetId([
            'clock_name' => 'Clock Main',
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
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
        }

        if (! Schema::hasTable('clocks')) {
            Schema::create('clocks', function (Blueprint $table): void {
                $table->id();
                $table->string('clock_name');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->timestamps();
            });
            $this->createdClocksTable = true;
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
}
