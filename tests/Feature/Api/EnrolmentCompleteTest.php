<?php

namespace Tests\Feature\Api;

use App\Models\EmployeeFingerprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnrolmentCompleteTest extends TestCase
{
    private const URI = '/api/FortiaPrimeApi.Opensync/api/v2/enrolments/complete';
    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdEnrolmentAuditsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
        }

        if (! Schema::hasTable('clocks')) {
            Schema::create('clocks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('clock_name')->default('Clock');
                $table->timestamps();
            });
            $this->createdClocksTable = true;
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->nullable();
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
                $table->string('vendor_template_id', 191)->nullable();
                $table->longText('template_b64')->nullable();
                $table->string('template_format', 40)->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('device_serial', 191)->nullable();
                $table->string('status', 30)->default('enrolled');
                $table->dateTime('enrolled_at')->nullable();
                $table->dateTime('performed_at')->nullable();
                $table->dateTime('deleted_at')->nullable();
                $table->timestamps();

                $table->index('vendor_template_id', 'employee_fingerprints_vendor_template_id_idx');
                $table->unique(
                    ['employee_id', 'vendor_template_id'],
                    'employee_fingerprints_employee_vendor_unique'
                );
            });
            $this->createdEmployeeFingerprintsTable = true;
        }

        if (! Schema::hasTable('enrolment_audits')) {
            Schema::create('enrolment_audits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('vendor_template_id', 191)->nullable();
                $table->string('device_serial', 191)->nullable();
                $table->dateTime('performed_at')->nullable();
                $table->string('status', 20);
                $table->string('reason', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
            $this->createdEnrolmentAuditsTable = true;
        }

        DB::table('enrolment_audits')->delete();
        EmployeeFingerprint::query()->delete();
        DB::table('employees')->delete();
        DB::table('clocks')->delete();
        DB::table('locations')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdEnrolmentAuditsTable && Schema::hasTable('enrolment_audits')) {
            Schema::drop('enrolment_audits');
        }
        if ($this->createdEmployeeFingerprintsTable && Schema::hasTable('employee_fingerprints')) {
            Schema::drop('employee_fingerprints');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdClocksTable && Schema::hasTable('clocks')) {
            Schema::drop('clocks');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }

        parent::tearDown();
    }

    public function test_complete_creates_new_enrolment_and_audit(): void
    {
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Unit A',
            'status' => 1,
        ]);
        $clockId = DB::table('clocks')->insertGetId(['location_id' => $locationId, 'clock_name' => 'Clock 1']);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 800001,
            'status' => 'A',
            'has_fingerprint' => 0,
        ]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-001',
            'template_b64' => base64_encode('template'),
            'template_format' => 'zkteco-v1',
            'device_serial' => 'DEVICE-123',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'CREATED')
            ->assertJsonPath('vendor_template_id', 'TPL-001');

        $this->assertDatabaseHas('employee_fingerprints', [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => 'TPL-001',
            'status' => 'enrolled',
        ]);

        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => 'TPL-001',
            'status' => 'SENT',
        ]);
    }

    public function test_complete_is_idempotent_for_same_employee_and_vendor_template(): void
    {
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Unit A',
            'status' => 1,
        ]);
        $clockId = DB::table('clocks')->insertGetId(['location_id' => $locationId, 'clock_name' => 'Clock 1']);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 800002,
            'status' => 'A',
            'has_fingerprint' => 1,
        ]);

        EmployeeFingerprint::query()->create([
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => 'TPL-REUSED',
            'status' => 'enrolled',
            'enrolled_at' => now(),
            'performed_at' => now(),
        ]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-REUSED',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'ALREADY')
            ->assertJsonPath('message', 'Already enrolled');

        $this->assertSame(1, EmployeeFingerprint::query()->count());
        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-REUSED',
            'status' => 'DUPLICATE',
        ]);
    }

    public function test_complete_returns_conflict_when_template_belongs_to_another_employee(): void
    {
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Unit A',
            'status' => 1,
        ]);
        $clockId = DB::table('clocks')->insertGetId(['location_id' => $locationId, 'clock_name' => 'Clock 1']);
        $employeeOne = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 800003,
            'status' => 'A',
            'has_fingerprint' => 1,
        ]);
        $employeeTwo = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 800004,
            'status' => 'A',
            'has_fingerprint' => 0,
        ]);

        EmployeeFingerprint::query()->create([
            'employee_id' => $employeeOne,
            'clock_id' => $clockId,
            'vendor_template_id' => 'TPL-CONFLICT',
            'status' => 'enrolled',
            'enrolled_at' => now(),
            'performed_at' => now(),
        ]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeTwo,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-CONFLICT',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('action', 'CONFLICT');

        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeTwo,
            'vendor_template_id' => 'TPL-CONFLICT',
            'status' => 'CONFLICT',
        ]);
    }

    public function test_complete_requires_template_b64_for_new_enrolment(): void
    {
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'Unit A',
            'status' => 1,
        ]);
        $clockId = DB::table('clocks')->insertGetId(['location_id' => $locationId, 'clock_name' => 'Clock 1']);
        $employeeId = DB::table('employees')->insertGetId([
            'fortia_employee_id' => 800005,
            'status' => 'A',
            'has_fingerprint' => 0,
        ]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-NEW-NO-B64',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.template_b64.0', 'The template_b64 field is required for new enrolments.');

        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-NEW-NO-B64',
            'status' => 'REJECTED',
        ]);
    }
}
