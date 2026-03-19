<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeFaceAdministrationTest extends TestCase
{
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->nullable()->unique();
                $table->string('status', 20)->default('A');
                $table->boolean('has_fingerprint')->default(false);
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'has_face_enrollment')) {
                $table->boolean('has_face_enrollment')->default(false);
            }
            if (! Schema::hasColumn('employees', 'face_status')) {
                $table->string('face_status', 30)->default('none');
            }
            if (! Schema::hasColumn('employees', 'face_samples_count')) {
                $table->unsignedInteger('face_samples_count')->default(0);
            }
            if (! Schema::hasColumn('employees', 'face_template_version')) {
                $table->string('face_template_version', 80)->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_updated_at')) {
                $table->dateTime('face_updated_at')->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_enabled')) {
                $table->boolean('face_enabled')->default(false);
            }
            if (! Schema::hasColumn('employees', 'face_quality_score')) {
                $table->unsignedSmallInteger('face_quality_score')->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_meta')) {
                $table->json('face_meta')->nullable();
            }
        });

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
            });
            $this->createdEmployeeFingerprintsTable = true;
        }

        DB::table('employee_fingerprints')->delete();
        DB::table('employees')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdEmployeeFingerprintsTable && Schema::hasTable('employee_fingerprints')) {
            Schema::drop('employee_fingerprints');
        }

        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }

        parent::tearDown();
    }

    public function test_refresh_fingerprint_flag_ignores_face_templates(): void
    {
        $employee = Employee::query()->create([
            'fortia_employee_id' => 88001,
            'status' => 'A',
            'has_fingerprint' => false,
        ]);

        EmployeeFingerprint::query()->create([
            'employee_id' => $employee->id,
            'vendor_template_id' => 'FACE-ONLY',
            'template_b64' => base64_encode('face-template'),
            'enrolment_type' => 'FACE',
            'status' => 'enrolled',
        ]);

        $employee->refreshFingerprintFlag();

        $this->assertFalse($employee->fresh()->has_fingerprint);

        EmployeeFingerprint::query()->create([
            'employee_id' => $employee->id,
            'vendor_template_id' => 'FP-ONLY',
            'template_b64' => base64_encode('finger-template'),
            'enrolment_type' => 'FINGERPRINT',
            'status' => 'enrolled',
        ]);

        $employee->refreshFingerprintFlag();

        $this->assertTrue($employee->fresh()->has_fingerprint);
    }

    public function test_mark_face_enrolled_sets_face_sync_ready_summary(): void
    {
        $employee = Employee::query()->create([
            'fortia_employee_id' => 88002,
            'status' => 'A',
            'has_fingerprint' => false,
        ]);

        $employee->markFaceEnrolled([
            'face_samples_count' => 3,
            'face_template_version' => 'FACE_V1',
            'face_quality_score' => 93,
            'face_updated_at' => now(),
            'vendor_template_id' => 'FACE-SUMMARY',
            'template_format' => 'FACE_EMBEDDING_V1',
            'device_serial' => 'DEVICE-FACE-001',
        ]);

        $employee = $employee->fresh();

        $this->assertTrue($employee->has_face_enrollment);
        $this->assertTrue($employee->face_enabled);
        $this->assertSame('enrolled', $employee->face_status);
        $this->assertSame(3, $employee->face_samples_count);
        $this->assertSame('FACE_V1', $employee->face_template_version);
        $this->assertSame(93, $employee->face_quality_score);
        $this->assertTrue($employee->face_sync_ready);
        $this->assertSame('FACE-SUMMARY', $employee->face_meta['last_vendor_template_id'] ?? null);
    }
}
