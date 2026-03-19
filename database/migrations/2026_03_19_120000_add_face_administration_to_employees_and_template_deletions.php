<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $table): void {
                if (! Schema::hasColumn('employees', 'has_face_enrollment')) {
                    $table->boolean('has_face_enrollment')->default(false)->after('has_fingerprint');
                }

                if (! Schema::hasColumn('employees', 'face_status')) {
                    $table->string('face_status', 30)->default('none')->after('has_face_enrollment');
                }

                if (! Schema::hasColumn('employees', 'face_samples_count')) {
                    $table->unsignedInteger('face_samples_count')->default(0)->after('face_status');
                }

                if (! Schema::hasColumn('employees', 'face_template_version')) {
                    $table->string('face_template_version', 80)->nullable()->after('face_samples_count');
                }

                if (! Schema::hasColumn('employees', 'face_updated_at')) {
                    $table->dateTime('face_updated_at')->nullable()->after('face_template_version');
                }

                if (! Schema::hasColumn('employees', 'face_enabled')) {
                    $table->boolean('face_enabled')->default(false)->after('face_updated_at');
                }

                if (! Schema::hasColumn('employees', 'face_quality_score')) {
                    $table->unsignedSmallInteger('face_quality_score')->nullable()->after('face_enabled');
                }

                if (! Schema::hasColumn('employees', 'face_meta')) {
                    $table->json('face_meta')->nullable()->after('face_quality_score');
                }
            });
        }

        if (Schema::hasTable('employee_template_deletions')) {
            Schema::table('employee_template_deletions', function (Blueprint $table): void {
                if (! Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
                    $table->string('biometric_type', 50)->nullable()->after('vendor');
                    $table->index('biometric_type', 'employee_template_deletions_biometric_type_idx');
                }
            });

            DB::table('employee_template_deletions')
                ->whereNull('biometric_type')
                ->update(['biometric_type' => 'FINGERPRINT']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_template_deletions')) {
            Schema::table('employee_template_deletions', function (Blueprint $table): void {
                if (Schema::hasColumn('employee_template_deletions', 'biometric_type')) {
                    $table->dropIndex('employee_template_deletions_biometric_type_idx');
                    $table->dropColumn('biometric_type');
                }
            });
        }

        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            foreach ([
                'face_meta',
                'face_quality_score',
                'face_enabled',
                'face_updated_at',
                'face_template_version',
                'face_samples_count',
                'face_status',
                'has_face_enrollment',
            ] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
