<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FaceAdministrationMigrationTest extends TestCase
{
    public function test_face_administration_migration_adds_expected_columns_idempotently(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->boolean('has_fingerprint')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_template_deletions')) {
            Schema::create('employee_template_deletions', function (Blueprint $table): void {
                $table->id();
                $table->string('vendor', 80)->default('digitalpersona');
                $table->string('vendor_template_id', 191);
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->dateTime('deleted_at');
                $table->timestamps();
            });
        }

        $migration = require database_path('migrations/2026_03_19_120000_add_face_administration_to_employees_and_template_deletions.php');

        $migration->up();
        $migration->up();

        foreach ([
            'has_face_enrollment',
            'face_status',
            'face_samples_count',
            'face_template_version',
            'face_updated_at',
            'face_enabled',
            'face_quality_score',
            'face_meta',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('employees', $column), "Missing employees.{$column} column.");
        }

        $this->assertTrue(Schema::hasColumn('employee_template_deletions', 'biometric_type'));
    }
}
