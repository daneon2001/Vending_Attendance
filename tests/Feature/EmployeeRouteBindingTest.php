<?php

namespace Tests\Feature;

use App\Http\Middleware\AuditBiometricAccess;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureStrictPermission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeRouteBindingTest extends TestCase
{
    private bool $createdEmployeesTable = false;
    private bool $createdFingerprintsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            Authenticate::class,
            AuditBiometricAccess::class,
            EnsureRole::class,
            EnsureStrictPermission::class,
            ThrottleRequests::class,
        ]);

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->unique();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('base_location_id')->nullable();
                $table->string('status', 20)->default('A');
                $table->string('name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('full_name')->nullable();
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
                $table->string('status', 30)->default('enrolled');
                $table->string('vendor_template_id')->nullable();
                $table->dateTime('deleted_at')->nullable();
                $table->timestamps();
            });
            $this->createdFingerprintsTable = true;
        }

        DB::table('employee_fingerprints')->delete();
        DB::table('employees')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdFingerprintsTable && Schema::hasTable('employee_fingerprints')) {
            Schema::drop('employee_fingerprints');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }

        parent::tearDown();
    }

    public function test_delete_fingerprint_resolves_employee_by_fortia_employee_id(): void
    {
        DB::table('employees')->insert([
            'id' => 501,
            'fortia_employee_id' => 90090,
            'status' => 'A',
            'full_name' => 'Empleado Prueba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson('/api/admin/employees/90090/fingerprints');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_count', 0);
    }
}
